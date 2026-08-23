<?php

declare(strict_types=1);

namespace Greenlight\Event;

use Greenlight\Internal\Wire\Wire;
use Greenlight\Result\ResultSummary;

final readonly class RunFinished implements WireEvent
{
    /**
     * @var non-empty-string
     */
    public string $runId;

    /**
     * @param list<WorkerTiming> $workerTimings Orchestrator-observed worker timing data.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $runId,
        public ResultSummary $summary,
        public float $durationSeconds,
        public float $occurredAt,
        public array $workerTimings = [],
    ) {
        if ($runId === '') {
            throw new \InvalidArgumentException('RunFinished requires a non-empty run ID.');
        }

        if (!\is_finite($durationSeconds)) {
            throw new \InvalidArgumentException('RunFinished duration is not finite.');
        }

        if ($durationSeconds < 0.0) {
            throw new \InvalidArgumentException('RunFinished duration cannot be negative.');
        }

        if (!\is_finite($occurredAt)) {
            throw new \InvalidArgumentException('Event timestamp MUST be finite.');
        }

        $this->runId = $runId;
    }

    /** @internal */
    #[\Override]
    public function toWire(): array
    {
        return [
            'runId' => $this->runId,
            'summary' => $this->summary->toWire(),
            'durationSeconds' => $this->durationSeconds,
            'occurredAt' => $this->occurredAt,
            ...($this->workerTimings === [] ? [] : [
                'workerTimings' => \array_map(static fn(WorkerTiming $timing): array => $timing->toWire(), $this->workerTimings),
            ]),
        ];
    }

    /** @internal */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'runId'),
            ResultSummary::fromWire(Wire::map($payload, 'summary')),
            \max(0.0, Wire::float($payload, 'durationSeconds')),
            Wire::float($payload, 'occurredAt'),
            \array_map(
                WorkerTiming::fromWire(...),
                \array_key_exists('workerTimings', $payload) ? Wire::listOfMaps($payload, 'workerTimings') : [],
            ),
        );
    }
}
