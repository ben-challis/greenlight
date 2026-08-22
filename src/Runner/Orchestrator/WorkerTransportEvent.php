<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Runner\Protocol\Message;

/**
 * Contains one transport observation and its applicable identity.
 *
 * Named constructors preserve the valid fields for each event kind.
 *
 * @internal
 */
final readonly class WorkerTransportEvent
{
    /** @param non-empty-string|null $workerId */
    private function __construct(
        public WorkerTransportEventKind $kind,
        public ?int $connectionId = null,
        public ?string $workerId = null,
        public ?Message $message = null,
    ) {}

    public static function connectionAccepted(int $connectionId): self
    {
        return new self(WorkerTransportEventKind::ConnectionAccepted, connectionId: $connectionId);
    }

    public static function connectionClosed(int $connectionId): self
    {
        return new self(WorkerTransportEventKind::ConnectionClosed, connectionId: $connectionId);
    }

    public static function connectionMessage(int $connectionId, Message $message): self
    {
        return new self(
            WorkerTransportEventKind::ConnectionMessage,
            connectionId: $connectionId,
            message: $message,
        );
    }

    /** @param non-empty-string $workerId */
    public static function workerMessage(string $workerId, Message $message): self
    {
        return new self(WorkerTransportEventKind::WorkerMessage, workerId: $workerId, message: $message);
    }

    /** @param non-empty-string $workerId */
    public static function workerDisconnected(string $workerId): self
    {
        return new self(WorkerTransportEventKind::WorkerDisconnected, workerId: $workerId);
    }

    /** @param non-empty-string $workerId */
    public static function workerRetired(string $workerId): self
    {
        return new self(WorkerTransportEventKind::WorkerRetired, workerId: $workerId);
    }
}
