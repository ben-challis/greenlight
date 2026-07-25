<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

/**
 * Greenlight raises this error when a frame, envelope, or message violates
 * the worker protocol. Causes include an oversized or truncated frame and
 * an unknown version or type tag. A difference between a worker summary and
 * the event stream also causes this error.
 *
 * @internal
 */
final class ProtocolError extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function frameTooLarge(int $length, int $limit): self
    {
        return new self(\sprintf('Frame of %d bytes exceeds the %d byte limit.', $length, $limit));
    }

    public static function malformedFrame(string $reason, ?string $warning = null): self
    {
        return new self(\sprintf(
            'Malformed frame: %s%s.',
            $reason,
            $warning === null ? '' : ': ' . $warning,
        ));
    }

    public static function unsupportedVersion(int $version): self
    {
        return new self(\sprintf('Unsupported protocol version %d.', $version));
    }

    public static function unknownType(string $tag): self
    {
        return new self(\sprintf('Unknown message type "%s".', $tag));
    }

    public static function unknownEvent(string $tag): self
    {
        return new self(\sprintf('Unknown event type "%s".', $tag));
    }

    public static function summaryMismatch(string $workerId, string $expected, string $reported): self
    {
        return new self(\sprintf(
            'Worker "%s" reported a summary of %s, but its event stream totals %s. '
            . 'This difference indicates an internal accounting error. Greenlight stopped the run.',
            $workerId,
            $reported,
            $expected,
        ));
    }

    public static function remainderMismatch(
        string $workerId,
        string $expected,
        string $reported,
    ): self {
        return new self(\sprintf(
            'Worker "%s" reported remaining tests %s. Greenlight expected %s from its active assignment.',
            $workerId,
            $reported,
            $expected,
        ));
    }

    public static function unexpectedAttempt(
        string $workerId,
        string $reportedTest,
        int $reportedAttempt,
        ?string $inFlightTest,
        int $expectedAttempt,
    ): self {
        return new self(\sprintf(
            'Worker "%s" reported attempt %d for "%s". Greenlight expected attempt %d. Active test: %s.',
            $workerId,
            $reportedAttempt,
            $reportedTest,
            $expectedAttempt,
            $inFlightTest === null ? 'none' : '"' . $inFlightTest . '"',
        ));
    }

    public static function workerNeverConnected(string $workerId, float $deadlineSeconds, string $diagnostics): self
    {
        $message = \sprintf(
            'Worker "%s" did not connect within %.1f seconds. '
            . 'The machine can have insufficient resources to start a worker. '
            . 'Greenlight stopped the run to prevent an unlimited wait.',
            $workerId,
            $deadlineSeconds,
        );

        if ($diagnostics !== '') {
            $message .= "\nWorker output:\n" . $diagnostics;
        }

        return new self($message);
    }

    public static function workerStalled(string $workerId, float $deadlineSeconds, string $diagnostics): self
    {
        $message = \sprintf(
            'Worker "%s" sent no message for %.1f seconds after connection. No test was active. '
            . 'The worker stopped responding between protocol messages. Greenlight stopped the run to prevent an unlimited wait.',
            $workerId,
            $deadlineSeconds,
        );

        if ($diagnostics !== '') {
            $message .= "\nWorker output:\n" . $diagnostics;
        }

        return new self($message);
    }

    public static function workerFatal(
        string $workerId,
        string $message,
        string $file,
        int $line,
        ?\Throwable $previous = null,
    ): self {
        return new self(\sprintf(
            'Worker "%s" reported a fatal Greenlight error: %s (%s:%d)',
            $workerId,
            $message,
            $file,
            $line,
        ), $previous);
    }
}
