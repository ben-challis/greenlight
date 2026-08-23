<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Internal\Wire\WireCommunicationFailed;
use Greenlight\Result\CapturedOutput;

/**
 * Controls worker processes and carries protocol messages for an orchestrator.
 *
 * The interface does not make scheduling or containment decisions.
 *
 * @internal
 */
interface WorkerTransport
{
    /** @return non-empty-string */
    public function token(): string;

    public function now(): float;

    /**
     * @param non-empty-string $workerId
     * @param positive-int $channelNumber
     *
     * @return positive-int Process ID.
     * @throws ProtocolError
     */
    public function start(string $workerId, int $channelNumber): int;

    /**
     * @return list<WorkerTransportEvent>
     * @throws ProtocolError
     * @throws WireCommunicationFailed
     */
    public function poll(): array;

    /**
     * Associates an accepted connection with a worker. A null worker rejects
     * the connection.
     *
     * @param non-empty-string|null $workerId
     */
    public function resolveConnection(int $connectionId, ?string $workerId): void;

    /**
     * @param non-empty-string $workerId
     * @throws ProtocolError
     */
    public function send(string $workerId, Message $message): void;

    /** @param non-empty-string $workerId */
    public function retire(string $workerId, bool $force = false): void;

    /** @param non-empty-string $workerId */
    public function diagnostics(string $workerId): string;

    /** @param non-empty-string $workerId */
    public function startOutputCapture(string $workerId, bool $enabled): void;

    /** @param non-empty-string $workerId */
    public function finishOutputCapture(string $workerId): ?CapturedOutput;

    public function close(): void;
}
