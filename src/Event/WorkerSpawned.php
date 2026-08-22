<?php

declare(strict_types=1);

namespace Greenlight\Event;

use Greenlight\Wire\Wire;

final readonly class WorkerSpawned implements Event
{
    /**
     * @var non-empty-string
     */
    public string $workerId;

    /**
     * @var positive-int
     */
    public int $pid;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $workerId,
        int $pid,
        public float $occurredAt,
    ) {
        if ($workerId === '') {
            throw new \InvalidArgumentException('Worker ID MUST NOT be empty.');
        }

        if ($pid < 1) {
            throw new \InvalidArgumentException('Worker PID MUST be greater than zero.');
        }

        if (!\is_finite($occurredAt)) {
            throw new \InvalidArgumentException('Event timestamp MUST be finite.');
        }

        $this->workerId = $workerId;
        $this->pid = $pid;
    }

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
            Wire::int($payload, 'pid'),
            Wire::float($payload, 'occurredAt'),
        );
    }
}
