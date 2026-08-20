<?php

declare(strict_types=1);

namespace Greenlight\Capture;

use Greenlight\Core\ErrorHandlerStack;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Result\CapturedOutput;
use Greenlight\Core\Result\Diagnostic;
use Greenlight\Core\Result\DiagnosticSeverity;
use Greenlight\Core\Wire\Utf8;

/**
 * Direct writes to stream resources bypass output capture. Examples include
 * fwrite(STDERR, ...) and fwrite(STDOUT, ...).
 *
 * If output is too long, Greenlight keeps the first part. This part usually
 * contains useful error information. The final part frequently contains
 * repeated information. Greenlight removes a partial multibyte character at
 * the size limit.
 *
 * @internal
 */
final class OutputCapture
{
    private const int DEFAULT_MAX_STDOUT_BYTES = 1_048_576;
    private const int DEFAULT_MAX_DIAGNOSTICS = 1_000;

    private string $stdout = '';
    private bool $stdoutTruncated = false;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];
    private bool $diagnosticsTruncated = false;

    /** Contains the ob_get_level() of the capture buffer, or null if inactive. */
    private ?int $bufferLevel = null;
    private bool $bufferClosed = false;

    /** @var (\Closure(int, string, string, int): bool)|null */
    private ?\Closure $errorHandler = null;

    public function __construct(
        private readonly int $maxStdoutBytes = self::DEFAULT_MAX_STDOUT_BYTES,
        private readonly int $maxDiagnostics = self::DEFAULT_MAX_DIAGNOSTICS,
    ) {
        if ($maxStdoutBytes < 1) {
            throw new \InvalidArgumentException(\sprintf('Stdout bound must be at least 1 byte, got %d.', $maxStdoutBytes));
        }

        if ($maxDiagnostics < 1) {
            throw new \InvalidArgumentException(\sprintf('Diagnostics bound must be at least 1 entry, got %d.', $maxDiagnostics));
        }
    }

    /**
     * Captures notices, warnings, and deprecations. PHP uses its default
     * behavior for masked or unsupported diagnostics.
     *
     * @throws CaptureError when a capture window is already active
     */
    public function start(): void
    {
        if ($this->bufferLevel !== null) {
            throw CaptureError::alreadyStarted();
        }

        $this->stdout = '';
        $this->stdoutTruncated = false;
        $this->diagnostics = [];
        $this->diagnosticsTruncated = false;
        $this->bufferClosed = false;

        $handler = function (int $severity, string $message, string $file = '', int $line = 0): bool {
            $mapped = DiagnosticSeverity::fromErrorLevel($severity);

            if (!$mapped instanceof DiagnosticSeverity || (\error_reporting() & $severity) === 0) {
                return false;
            }

            $this->recordDiagnostic(new Diagnostic($mapped, Utf8::scrub($message), Utf8::scrub($file), $line));

            return true;
        };

        $this->errorHandler = $handler;
        \set_error_handler($handler);

        \ob_start($this->handleChunk(...), 1);
        $this->bufferLevel = \ob_get_level();
    }

    private function handleChunk(string $chunk, int $phase): string
    {
        if (($phase & \PHP_OUTPUT_HANDLER_FINAL) !== 0) {
            $this->bufferClosed = true;
        }

        return $this->appendChunk($chunk);
    }

    /**
     * Stops output capture safely from a finally block after buffer-stack
     * changes. The method does not remove a handler that user code installs
     * during output capture.
     *
     * @throws CaptureError when no capture window is active or a nested buffer cannot be removed
     */
    public function stop(): CapturedOutput
    {
        if ($this->bufferLevel === null) {
            throw CaptureError::notStarted();
        }

        $level = $this->bufferLevel;
        $this->bufferLevel = null;

        while (\ob_get_level() > $level) {
            $previousLevel = \ob_get_level();
            $removed = ErrorTrap::run(static fn() => \ob_end_flush());

            if (!$removed || \ob_get_level() >= $previousLevel) {
                $this->restoreErrorHandler();

                throw CaptureError::nestedBufferCannotBeRemoved();
            }
        }

        if (!$this->bufferClosed && \ob_get_level() === $level) {
            \ob_end_clean();
        }

        $this->restoreErrorHandler();

        $scrubbedStdout = Utf8::scrub($this->stdout);
        $boundedStdout = Utf8::headBytes($scrubbedStdout, $this->maxStdoutBytes);
        $this->stdoutTruncated = $this->stdoutTruncated
            || \strlen($boundedStdout) < \strlen($scrubbedStdout);

        $captured = new CapturedOutput(
            $boundedStdout,
            $this->diagnostics,
            $this->stdoutTruncated,
            $this->diagnosticsTruncated,
        );

        $this->stdout = '';
        $this->diagnostics = [];

        return $captured;
    }

    /**
     * Keeps the first part within the size limit. It returns an empty string to
     * stop propagation. This callback MUST NOT throw because an output-handler
     * exception is fatal.
     */
    private function appendChunk(string $chunk): string
    {
        if ($this->stdoutTruncated || $chunk === '') {
            return '';
        }

        $combined = $this->stdout . $chunk;

        if (\strlen($combined) <= $this->maxStdoutBytes) {
            $this->stdout = $combined;

            return '';
        }

        $this->stdout = Utf8::headBytes($combined, $this->maxStdoutBytes);
        $this->stdoutTruncated = true;

        return '';
    }

    private function recordDiagnostic(Diagnostic $diagnostic): void
    {
        if (\count($this->diagnostics) >= $this->maxDiagnostics) {
            $this->diagnosticsTruncated = true;

            return;
        }

        $this->diagnostics[] = $diagnostic;
    }

    private function restoreErrorHandler(): void
    {
        $handler = $this->errorHandler;
        $this->errorHandler = null;

        if (!$handler instanceof \Closure) {
            return;
        }

        ErrorHandlerStack::remove($handler);
    }
}
