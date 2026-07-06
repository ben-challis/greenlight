<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Wire\Wire;

/**
 * @internal
 */
final readonly class WorkerSpawned implements Event
{
    /**
     * @param non-empty-string $workerId
     * @param positive-int $pid
     */
    public function __construct(
        public string $workerId,
        public int $pid,
        public float $occurredAt,
    ) {}

    #[\Override]
    public function toWire(): array
    {
        return [
            'workerId' => $this->workerId,
            'pid' => $this->pid,
            'occurredAt' => $this->occurredAt,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'workerId'),
            \max(1, Wire::int($payload, 'pid')),
            Wire::float($payload, 'occurredAt'),
        );
    }
}
