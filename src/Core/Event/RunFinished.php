<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Wire\Wire;

/**
 * @internal
 */
final readonly class RunFinished implements Event
{
    /**
     * @param non-empty-string $runId
     */
    public function __construct(
        public string $runId,
        public ResultSummary $summary,
        public float $durationSeconds,
        public float $occurredAt,
    ) {}

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
