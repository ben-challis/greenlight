<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Protocol;

/**
 * Decodes frames in parts from a byte stream.
 *
 * feed() accepts bytes when they arrive from the socket. next() returns
 * complete frame bodies. The buffer keeps a partial frame until the remaining
 * bytes arrive.
 *
 * @internal
 */
final class FrameBuffer
{
    private string $buffer = '';

    /**
     * @var positive-int
     */
    private readonly int $maxFrameBytes;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(int $maxFrameBytes = JsonFrameCodec::DEFAULT_MAX_FRAME_BYTES)
    {
        if ($maxFrameBytes < 1) {
            throw new \InvalidArgumentException('Maximum frame size must be greater than zero.');
        }

        $this->maxFrameBytes = $maxFrameBytes;
    }

    public function feed(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    public function hasPendingBytes(): bool
    {
        return $this->buffer !== '';
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
