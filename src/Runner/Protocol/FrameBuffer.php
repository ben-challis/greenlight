<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

/**
 * Decodes frames incrementally from a byte stream.
 *
 * feed() accepts bytes as they arrive from the socket. next() yields
 * complete frame bodies as they become available, holding partial frames
 * until the rest arrives.
 *
 * @internal
 */
final class FrameBuffer
{
    private string $buffer = '';

    public function __construct(private readonly int $maxFrameBytes = JsonFrameCodec::DEFAULT_MAX_FRAME_BYTES) {}

    public function feed(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    /**
     * @return non-empty-string|null the next complete frame body, or null when more bytes are needed
     *
     * @throws ProtocolError
     */
    public function next(): ?string
    {
        if (\strlen($this->buffer) < 4) {
            return null;
        }

        /** @var array{1: int} $unpacked */
        $unpacked = \unpack('N', $this->buffer);
        $length = $unpacked[1];

        if ($length > $this->maxFrameBytes) {
            throw ProtocolError::frameTooLarge($length, $this->maxFrameBytes);
        }

        if ($length === 0) {
            throw ProtocolError::malformedFrame('zero-length frame');
        }

        if (\strlen($this->buffer) < 4 + $length) {
            return null;
        }

        $body = \substr($this->buffer, 4, $length);
        $this->buffer = \substr($this->buffer, 4 + $length);

        if ($body === '') {
            throw ProtocolError::malformedFrame('empty frame body');
        }

        return $body;
    }
}
