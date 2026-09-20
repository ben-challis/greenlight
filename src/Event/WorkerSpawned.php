<?php

declare(strict_types=1);

namespace Greenlight\Event;

use Greenlight\Internal\Wire\Wire;

final readonly class WorkerSpawned implements WireEvent
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
            throw new \InvalidArgumentException('Worker ID cannot be empty.');
        }

        if ($pid < 1) {
            throw new \InvalidArgumentException('Use a worker PID greater than zero.');
        }

        if (!\is_finite($occurredAt)) {
            throw new \InvalidArgumentException('Use a finite event timestamp.');
        }

        $this->workerId = $workerId;
        $this->pid = $pid;
    }

    /** @internal */
    #[\Override]
    public function toWire(): array
    {
        return [
            'workerId' => $this->workerId,
            'pid' => $this->pid,
            'occurredAt' => $this->occurredAt,
        ];
    }

    /** @internal */
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
