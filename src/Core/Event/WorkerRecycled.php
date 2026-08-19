<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Wire\Wire;

final readonly class WorkerRecycled implements Event
{
    /**
     * @var non-empty-string
     */
    public string $workerId;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $workerId,
        public RecycleReason $reason,
        public float $occurredAt,
    ) {
        if ($workerId === '') {
            throw new \InvalidArgumentException('Worker ID MUST NOT be empty.');
        }

        if (!\is_finite($occurredAt)) {
            throw new \InvalidArgumentException('Event timestamp MUST be finite.');
        }

        $this->workerId = $workerId;
    }

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
            Wire::enum($payload, 'reason', RecycleReason::class),
            Wire::float($payload, 'occurredAt'),
        );
    }
}
