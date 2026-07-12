<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

/**
 * Raised when a frame, envelope, or message violates the protocol: oversized
 * or truncated frames, unknown versions or type tags, or bookkeeping
 * mismatches between a worker's summary and the event stream.
 *
 * @internal
 */
final class ProtocolError extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
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
            'Worker "%s" reported a summary of %s but its event stream tallies %s. '
            . 'A bookkeeping bug must fail the run, never report green.',
            $workerId,
            $reported,
            $expected,
        ));
    }

    public static function workerNeverConnected(string $workerId, float $deadlineSeconds, string $diagnostics): self
    {
        $message = \sprintf(
            'Worker "%s" spawned but never connected within %.1fs. '
            . 'The machine may be too starved to boot worker processes; failing the run beats waiting forever.',
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
            'Worker "%s" connected but then sent nothing for %.1fs with no test in flight. '
            . 'The worker process stopped responding between messages; failing the run beats waiting forever.',
            $workerId,
            $deadlineSeconds,
        );

        if ($diagnostics !== '') {
            $message .= "\nWorker output:\n" . $diagnostics;
        }

        return new self($message);
    }

    public static function workerFatal(string $workerId, string $message, string $file, int $line): self
    {
        return new self(\sprintf(
            'Worker "%s" reported a fatal framework error: %s (%s:%d)',
            $workerId,
            $message,
            $file,
            $line,
        ));
    }
}
