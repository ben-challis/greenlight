<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

use Greenlight\Internal\Php\ErrorHandlerStack;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Internal\Text\Utf8;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Diagnostic;
use Greenlight\Result\DiagnosticSeverity;
use Greenlight\Result\OutputCaptureCapability;

/**
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
    private const int DEFAULT_MAX_STDERR_BYTES = 1_048_576;
    private const int DEFAULT_MAX_DIAGNOSTICS = 1_000;
    private const string STREAM_FILTER = 'greenlight.output-capture';

    private static bool $streamFilterRegistered = false;

    private OutputStreamBuffer $stdout;
    private OutputStreamBuffer $stderr;

    /** @var resource|null */
    private mixed $stdoutFilter = null;

    /** @var resource|null */
    private mixed $stderrFilter = null;

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
        private readonly int $maxStderrBytes = self::DEFAULT_MAX_STDERR_BYTES,
        private readonly OutputRouting $routing = OutputRouting::CapturePhpStreams,
    ) {
        if ($maxStdoutBytes < 1) {
            throw new \InvalidArgumentException(\sprintf('Stdout bound must be at least 1 byte, got %d.', $maxStdoutBytes));
        }

        if ($maxDiagnostics < 1) {
            throw new \InvalidArgumentException(\sprintf('Diagnostics bound must be at least 1 entry, got %d.', $maxDiagnostics));
        }

        if ($maxStderrBytes < 1) {
            throw new \InvalidArgumentException(\sprintf('Stderr bound must be at least 1 byte, got %d.', $maxStderrBytes));
        }

        $this->stdout = new OutputStreamBuffer($maxStdoutBytes);
        $this->stderr = new OutputStreamBuffer($maxStderrBytes);
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

        $this->stdout = new OutputStreamBuffer($this->maxStdoutBytes);
        $this->stderr = new OutputStreamBuffer($this->maxStderrBytes);
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

        if ($this->routing === OutputRouting::CapturePhpStreams) {
            $this->attachStreamFilters();
        }

        \ob_start($this->handleChunk(...), 1);
        $this->bufferLevel = \ob_get_level();
    }

    private function handleChunk(string $chunk, int $phase): string
    {
        if (($phase & \PHP_OUTPUT_HANDLER_FINAL) !== 0) {
            $this->bufferClosed = true;
        }

        if ($this->routing === OutputRouting::ForwardToProcess) {
            return $chunk;
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
                $this->removeStreamFilters();
                $this->restoreErrorHandler();

                throw CaptureError::nestedBufferCannotBeRemoved();
            }
        }

        if (!$this->bufferClosed && \ob_get_level() === $level) {
            if ($this->routing === OutputRouting::ForwardToProcess) {
                \ob_end_flush();
            } else {
                \ob_end_clean();
            }
        }

        $this->removeStreamFilters();
        $this->restoreErrorHandler();

        $captured = $this->snapshot();

        $this->stdout = new OutputStreamBuffer($this->maxStdoutBytes);
        $this->stderr = new OutputStreamBuffer($this->maxStderrBytes);
        $this->diagnostics = [];

        return $captured;
    }

    /**
     * Returns the output recorded so far without closing the capture window.
     *
     * @internal
     * @throws CaptureError when no capture window is active
     */
    public function snapshot(): CapturedOutput
    {
        if ($this->bufferLevel === null && !$this->bufferClosed) {
            throw CaptureError::notStarted();
        }

        $scrubbedStdout = Utf8::scrub($this->stdout->bytes);
        $scrubbedStderr = Utf8::scrub($this->stderr->bytes);
        $boundedStdout = Utf8::headBytes($scrubbedStdout, $this->maxStdoutBytes);
        $boundedStderr = Utf8::headBytes($scrubbedStderr, $this->maxStderrBytes);

        return new CapturedOutput(
            $boundedStdout,
            $this->diagnostics,
            $this->stdout->truncated || \strlen($boundedStdout) < \strlen($scrubbedStdout),
            $this->diagnosticsTruncated,
            $boundedStderr,
            $this->stderr->truncated || \strlen($boundedStderr) < \strlen($scrubbedStderr),
            $this->routing === OutputRouting::CapturePhpStreams
                ? OutputCaptureCapability::PhpStreams
                : OutputCaptureCapability::Buffered,
        );
    }

    /**
     * Keeps the first part within the size limit. It returns an empty string to
     * stop propagation. This callback MUST NOT throw because an output-handler
     * exception is fatal.
     */
    private function appendChunk(string $chunk): string
    {
        $this->stdout->append($chunk);

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

    /** @throws CaptureError */
    private function attachStreamFilters(): void
    {
        if (!self::$streamFilterRegistered
            && !\stream_filter_register(self::STREAM_FILTER, CapturedStreamFilter::class)
        ) {
            $this->restoreErrorHandler();

            throw CaptureError::streamFilterUnavailable('STDOUT and STDERR');
        }

        self::$streamFilterRegistered = true;

        $stdoutFilter = ErrorTrap::run(
            fn() => \stream_filter_append(\STDOUT, self::STREAM_FILTER, \STREAM_FILTER_WRITE, $this->stdout),
        );

        if (!\is_resource($stdoutFilter)) {
            $this->restoreErrorHandler();

            throw CaptureError::streamFilterUnavailable('STDOUT');
        }

        $this->stdoutFilter = $stdoutFilter;

        $stderrFilter = ErrorTrap::run(
            fn() => \stream_filter_append(\STDERR, self::STREAM_FILTER, \STREAM_FILTER_WRITE, $this->stderr),
        );

        if (!\is_resource($stderrFilter)) {
            $this->removeStreamFilters();
            $this->restoreErrorHandler();

            throw CaptureError::streamFilterUnavailable('STDERR');
        }

        $this->stderrFilter = $stderrFilter;
    }

    private function removeStreamFilters(): void
    {
        foreach (['stderrFilter', 'stdoutFilter'] as $property) {
            $filter = $this->{$property};
            $this->{$property} = null;

            if (\is_resource($filter)) {
                ErrorTrap::run(static fn() => \stream_filter_remove($filter));
            }
        }
    }
}
