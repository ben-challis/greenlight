<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Protocol\Messages;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Result\ResultSummary;
use Greenlight\Test\TestId;
use Greenlight\Wire\Wire;

/**
 * Tells the orchestrator that a worker completed an assignment.
 *
 * The orchestrator compares the summary to the event stream. A difference
 * fails the run.
 *
 * @internal
 */
final readonly class Done implements Message
{
    /**
     * @var non-negative-int
     */
    public int $peakMemoryBytes;

    /**
     * @param list<TestId> $leaks
     *
     * @throws \InvalidArgumentException when peak memory is negative
     */
    public function __construct(
        public ResultSummary $summary,
        int $peakMemoryBytes,
        public ?CoverageMap $coverage = null,
        public array $leaks = [],
    ) {
        if ($peakMemoryBytes < 0) {
            throw new \InvalidArgumentException(\sprintf(
                'Done message peak memory MUST NOT be negative. Actual value: %d.',
                $peakMemoryBytes,
            ));
        }

        $this->peakMemoryBytes = $peakMemoryBytes;
    }

    #[\Override]
    public static function tag(): string
    {
        return 'done';
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'summary' => $this->summary->toWire(),
            'peakMemoryBytes' => $this->peakMemoryBytes,
            'coverage' => $this->coverage?->toWire(),
            'leaks' => \array_map(static fn(TestId $id): array => $id->toWire(), $this->leaks),
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $coverage = Wire::nullableMap($payload, 'coverage');
        return new self(
            ResultSummary::fromWire(Wire::map($payload, 'summary')),
            \max(0, Wire::int($payload, 'peakMemoryBytes')),
            $coverage === null ? null : CoverageMap::fromWire($coverage),
            \array_map(TestId::fromWire(...), Wire::listOfMaps($payload, 'leaks')),
        );
    }
}
