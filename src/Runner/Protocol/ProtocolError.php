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
    public static function frameTooLarge(int $length, int $limit): self
    {
        return new self(\sprintf('Frame of %d bytes exceeds the %d byte limit.', $length, $limit));
    }

    public static function malformedFrame(string $reason): self
    {
        return new self(\sprintf('Malformed frame: %s.', $reason));
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
}
