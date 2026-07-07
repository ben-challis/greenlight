<?php

declare(strict_types=1);

namespace Greenlight\Capture;

use Greenlight\Core\Result\CapturedOutput;
use Greenlight\Core\Result\Diagnostic;
use Greenlight\Core\Result\DiagnosticSeverity;
use Greenlight\Core\Wire\Utf8;

/**
 * Captures stdout and PHP diagnostics between start() and stop().
 *
 * Stdout is captured through an output buffer callback, so echo, print, and
 * printf output from user code is stored here instead of being written to the
 * real stream. PHP diagnostics such as notices, warnings, and deprecations are
 * recorded by an error handler installed for the capture window. See start()
 * for the exact behaviour.
 *
 * start() adds one output buffer level and remembers it. User code can still
 * open and close its own buffers inside the capture window; ob_get_clean() and
 * similar functions keep working on those user-created levels as normal.
 *
 * stop() flushes any user buffers that are still open into the capture, then
 * removes only the buffer level installed by start(). If user code has already
 * closed that buffer, stop() keeps whatever was captured before it was closed
 * and does not fail. This keeps stop() safe to call from a finally block
 * without hiding the original exception.
 *
 * Only output that goes through PHP output buffering can be captured. Direct
 * writes to stream resources, such as fwrite(STDERR, ...) or fwrite(STDOUT, ...),
 * bypass userland output buffering and are handled separately by the
 * orchestrator.
 *
 * When output is truncated, the start of the output is kept because it usually
 * contains the useful error context; the end is often repeated noise. A cut may
 * split a multibyte character, in which case the final scrub replaces the
 * dangling bytes with U+FFFD.
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

    /** The ob_get_level() of the buffer this capture installed, or null when inactive. */
    private ?int $bufferLevel = null;

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
     * Installs the capture. The error handler records notices, warnings, and
     * deprecations and reports them handled, so they do not leak into the
     * captured stdout or the real streams. Severities outside those levels,
     * and diagnostics masked by error_reporting() or the @ operator, are
     * passed back to PHP's default handling by returning false, so nothing
     * the engine would escalate or fatal on is suppressed. Exceptions never
     * pass through an error handler and are never swallowed here.
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

        \ob_start($this->appendChunk(...), 1);
        $this->bufferLevel = \ob_get_level();
    }

    /**
     * Removes the capture and returns what it collected. Safe to call from a
     * finally block: it restores buffers and the error handler regardless of
     * how the window ended, and never throws for buffer-stack drift caused
     * by user code. If user code installed its own error handler during the
     * window and left it active, that handler is deliberately left in place;
     * only the capture's own handler is removed when it is still on top.
     *
     * @throws CaptureError when no capture window is active
     */
    public function stop(): CapturedOutput
    {
        if ($this->bufferLevel === null) {
            throw CaptureError::notStarted();
        }

        $level = $this->bufferLevel;
        $this->bufferLevel = null;

        while (\ob_get_level() > $level) {
            \ob_end_flush();
        }

        if (\ob_get_level() === $level) {
            \ob_end_clean();
        }

        $active = \set_error_handler(null);
        \restore_error_handler();

        if ($active === $this->errorHandler) {
            \restore_error_handler();
        }

        $this->errorHandler = null;

        $captured = new CapturedOutput(
            Utf8::scrub($this->stdout),
            $this->diagnostics,
            $this->stdoutTruncated,
            $this->diagnosticsTruncated,
        );

        $this->stdout = '';
        $this->diagnostics = [];

        return $captured;
    }

    /**
     * Output buffer callback. Appends up to the configured bound and
     * discards the rest, keeping the head. Returns an empty string so
     * nothing propagates to the next buffer or the real stream. Must never
     * throw: an exception inside an output handler is fatal.
     */
    private function appendChunk(string $chunk, int $phase): string
    {
        $remaining = $this->maxStdoutBytes - \strlen($this->stdout);

        if ($remaining >= \strlen($chunk)) {
            $this->stdout .= $chunk;
        } else {
            if ($remaining > 0) {
                $this->stdout .= \substr($chunk, 0, $remaining);
            }

            if ($chunk !== '') {
                $this->stdoutTruncated = true;
            }
        }

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
}
