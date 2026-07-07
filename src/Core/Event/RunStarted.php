<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Wire\Wire;

final readonly class RunStarted implements Event
{
    /**
     * @param non-empty-string $runId
     * @param non-negative-int $plannedTests
     * @param positive-int $workers
     */
    public function __construct(
        public string $runId,
        public int $plannedTests,
        public int $workers,
        public float $occurredAt,
    ) {}

    #[\Override]
    public function toWire(): array
    {
        return [
            'runId' => $this->runId,
            'plannedTests' => $this->plannedTests,
            'workers' => $this->workers,
            'occurredAt' => $this->occurredAt,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'runId'),
            \max(0, Wire::int($payload, 'plannedTests')),
            \max(1, Wire::int($payload, 'workers')),
            Wire::float($payload, 'occurredAt'),
        );
    }
}
