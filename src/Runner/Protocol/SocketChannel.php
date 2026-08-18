<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Wire\InvalidWirePayload;

/**
 * Sends framed messages through one stream socket.
 *
 * send() waits until it writes the complete frame. It continues after a partial write.
 *
 * receive() waits for a message with a timeout. poll() returns without a wait.
 *
 * @internal
 */
final class SocketChannel
{
    private readonly FrameBuffer $buffer;

    private bool $eof = false;

    /**
     * @param resource $stream
     */
    public function __construct(
        private $stream,
        private readonly JsonFrameCodec $codec = new JsonFrameCodec(),
    ) {
        $this->buffer = new FrameBuffer($this->codec->maxFrameBytes);
    }

    /**
     * @throws ProtocolError when the peer is gone or the frame is invalid
     */
    public function send(Message $message): void
    {
        if (!\is_resource($this->stream)) {
            throw ProtocolError::malformedFrame('the channel is closed');
        }

        // poll() leaves the stream in a mode that does not wait. In this mode,
        // a large frame in a full socket buffer can cause a partial or
        // zero-byte write.
        // A zero-byte write looks like a closed peer. Always use a mode that
        // waits for writes.
        \stream_set_blocking($this->stream, true);

        $bytes = $this->codec->encode(MessageRegistry::envelope($message));

        $completed = ErrorTrap::run(function () use ($bytes): bool {
            $remaining = \strlen($bytes);

            while ($remaining > 0) {
                $written = \fwrite($this->stream, \substr($bytes, -$remaining));

                if ($written === false || $written === 0) {
                    return false;
                }

                $remaining -= $written;
            }

            return \fflush($this->stream);
        }, $warning);

        if (!$completed) {
            throw ProtocolError::malformedFrame('peer closed the connection during a write', $warning);
        }
    }

    /**
     * Waits up to the timeout for the next message.
     *
     * Null means that the timeout elapsed. Peer EOF causes a protocol error
     * unless the buffer already contains a complete frame.
     *
     * @throws ProtocolError
     * @throws InvalidWirePayload
     */
    public function receive(float $timeoutSeconds): ?Message
    {
        $deadline = \microtime(true) + $timeoutSeconds;

        while (true) {
            $message = $this->poll();

            if ($message instanceof Message) {
                return $message;
            }

            $left = $deadline - \microtime(true);

            if ($left <= 0 || $this->eof) {
                return null;
            }

            $read = [$this->stream];
            $write = null;
            $except = null;
            $microseconds = (int) \min($left * 1_000_000, 200_000);

            try {
                $ready = ErrorTrap::run(
                    static fn(): int|false => \stream_select($read, $write, $except, 0, \max(1, $microseconds)),
                );
            } catch (\ValueError) {
                return null;
            }

            if ($ready === false) {
                return null;
            }
        }
    }

    /**
     * Reads available bytes without a wait.
     *
     * Returns the next complete message or null.
     *
     * @throws ProtocolError
     * @throws InvalidWirePayload
     */
    public function poll(): ?Message
    {
        $body = $this->buffer->next();

        if ($body !== null) {
            return MessageRegistry::open($this->codec->decode($body));
        }

        if ($this->eof) {
            if ($this->buffer->hasPendingBytes()) {
                throw ProtocolError::malformedFrame('peer closed the connection with an incomplete frame');
            }

            return null;
        }

        if (!\is_resource($this->stream)) {
            $this->eof = true;

            return null;
        }

        \stream_set_blocking($this->stream, false);

        $reachedEof = ErrorTrap::run(function (): bool {
            $bytes = \fread($this->stream, 65536);

            if (\is_string($bytes) && $bytes !== '') {
                $this->buffer->feed($bytes);

                return false;
            }

            return \feof($this->stream);
        });

        if ($reachedEof) {
            $this->eof = true;
        }

        $body = $this->buffer->next();

        if ($body === null) {
            if ($this->eof && $this->buffer->hasPendingBytes()) {
                throw ProtocolError::malformedFrame('peer closed the connection with an incomplete frame');
            }

            return null;
        }

        return MessageRegistry::open($this->codec->decode($body));
    }

    public function isEof(): bool
    {
        return $this->eof;
    }

    /**
     * @return resource
     */
    public function stream()
    {
        return $this->stream;
    }

    public function close(): void
    {
        $this->eof = true;

        if (\is_resource($this->stream)) {
            ErrorTrap::run(fn(): bool => \fclose($this->stream));
        }
    }
}
