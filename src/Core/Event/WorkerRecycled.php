<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Wire\Wire;

final readonly class WorkerRecycled implements Event
{
    /**
     * @param non-empty-string $workerId
     */
    public function __construct(
        public string $workerId,
        public RecycleReason $reason,
        public float $occurredAt,
    ) {}

    #[\Override]
    public function toWire(): array
    {
        return [
            'workerId' => $this->workerId,
            'reason' => $this->reason->value,
            'occurredAt' => $this->occurredAt,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'workerId'),
            RecycleReason::from(Wire::nonEmptyString($payload, 'reason')),
            Wire::float($payload, 'occurredAt'),
        );
    }
}
