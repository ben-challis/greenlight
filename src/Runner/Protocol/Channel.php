<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

/**
 * Exchanges framed messages for one worker connection.
 *
 * @internal
 */
interface Channel
{
    /**
     * @throws ProtocolError when the peer is gone or the frame is invalid
     */
    public function send(Message $message): void;

    /**
     * Waits up to the timeout for the next message.
     *
     * Null means that the timeout elapsed. Peer EOF causes a protocol error
     * unless the buffer already contains a complete frame.
     *
     * @throws ProtocolError
     */
    public function receive(float $timeoutSeconds): ?Message;

    /**
     * Reads available bytes without a wait.
     *
     * Returns the next complete message or null.
     *
     * @throws ProtocolError
     */
    public function poll(): ?Message;

    public function isEof(): bool;

    public function close(): void;
}
