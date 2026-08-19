<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Wire\Wire;

final readonly class RunFinished implements Event
{
    /**
     * @var non-empty-string
     */
    public string $runId;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $runId,
        public ResultSummary $summary,
        public float $durationSeconds,
        public float $occurredAt,
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

    #[\Override]
    public function toWire(): array
    {
        return [
            'runId' => $this->runId,
            'summary' => $this->summary->toWire(),
            'durationSeconds' => $this->durationSeconds,
            'occurredAt' => $this->occurredAt,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'runId'),
            ResultSummary::fromWire(Wire::map($payload, 'summary')),
            \max(0.0, Wire::float($payload, 'durationSeconds')),
            Wire::float($payload, 'occurredAt'),
        );
    }
}
