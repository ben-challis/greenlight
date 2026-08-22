<?php

declare(strict_types=1);

namespace Greenlight\Event;

use Greenlight\Internal\Wire\Wire;

final readonly class RunStarted implements WireEvent
{
    /**
     * @var non-empty-string
     */
    public string $runId;

    /**
     * @var non-negative-int
     */
    public int $plannedTests;

    /**
     * @var positive-int
     */
    public int $workers;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $runId,
        int $plannedTests,
        int $workers,
        public float $occurredAt,
        public ?string $artifactsDirectory = null,
    ) {
        if ($runId === '') {
            throw new \InvalidArgumentException('RunStarted requires a non-empty run ID.');
        }

        if ($plannedTests < 0) {
            throw new \InvalidArgumentException(\sprintf(
                'RunStarted requires a non-negative planned test count. Actual value: %d.',
                $plannedTests,
            ));
        }

        if ($workers < 1) {
            throw new \InvalidArgumentException(\sprintf(
                'RunStarted requires at least one worker. Actual value: %d.',
                $workers,
            ));
        }

        if (!\is_finite($occurredAt)) {
            throw new \InvalidArgumentException('Event timestamp MUST be finite.');
        }

        $this->runId = $runId;
        $this->plannedTests = $plannedTests;
        $this->workers = $workers;
    }

    /** @internal */
    #[\Override]
    public function toWire(): array
    {
        return [
            'runId' => $this->runId,
            'plannedTests' => $this->plannedTests,
            'workers' => $this->workers,
            'occurredAt' => $this->occurredAt,
            'artifactsDirectory' => $this->artifactsDirectory,
        ];
    }

    /** @internal */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'runId'),
            \max(0, Wire::int($payload, 'plannedTests')),
            \max(1, Wire::int($payload, 'workers')),
            Wire::float($payload, 'occurredAt'),
            \array_key_exists('artifactsDirectory', $payload) ? Wire::nullableString($payload, 'artifactsDirectory') : null,
        );
    }
}
