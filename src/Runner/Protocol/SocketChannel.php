<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

use Greenlight\Core\ErrorTrap;

/**
 * A framed message channel over one stream socket.
 *
 * send() is blocking and complete: partial writes are retried until the
 * whole frame is written.
 *
 * Receiving is either receive(), which blocks with a timeout, or poll(),
 * which never blocks.
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

        // poll() leaves the stream non-blocking; a large frame written to a
        // full socket buffer would then short-write or return zero, which is
        // indistinguishable from a closed peer. Writes are always blocking.
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

            \fflush($this->stream);

            return true;
        }, $warning);

        if (!$completed) {
            throw ProtocolError::malformedFrame('peer closed the connection during a write', $warning);
        }
    }

    /**
     * Blocks up to the timeout for the next message. Null means the timeout
     * elapsed; EOF from the peer raises a protocol error unless a complete
     * frame was already buffered.
     *
     * @throws ProtocolError
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

            $ready = ErrorTrap::run(
                static fn(): int|false => \stream_select($read, $write, $except, 0, \max(1, $microseconds)),
            );

            if ($ready === false) {
                return null;
            }
        }
    }

    /**
     * Non-blocking: drains available bytes and returns the next complete
     * message, if any.
     *
     * @throws ProtocolError
     */
    public function poll(): ?Message
    {
        $body = $this->buffer->next();

        if ($body !== null) {
            return MessageRegistry::open($this->codec->decode($body));
        }

        if ($this->eof) {
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
