<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

/**
 * A framed message channel over one stream socket. Sending is blocking and
 * complete (partial writes are retried); receiving is either blocking with a
 * timeout or a non-blocking poll.
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
        $bytes = $this->codec->encode(MessageRegistry::envelope($message));
        $remaining = \strlen($bytes);

        while ($remaining > 0) {
            $written = @\fwrite($this->stream, \substr($bytes, -$remaining));

            if ($written === false || $written === 0) {
                throw ProtocolError::malformedFrame('peer closed the connection during a write');
            }

            $remaining -= $written;
        }

        \fflush($this->stream);
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

            if (@\stream_select($read, $write, $except, 0, \max(1, $microseconds)) === false) {
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

        \stream_set_blocking($this->stream, false);
        $bytes = @\fread($this->stream, 65536);

        if (\is_string($bytes) && $bytes !== '') {
            $this->buffer->feed($bytes);
        } elseif (@\feof($this->stream)) {
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
        @\fclose($this->stream);
    }
}
