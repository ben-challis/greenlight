<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\Wire;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Runner\Protocol\Message;

/**
 * Worker to orchestrator: assignment complete. The summary is cross-checked
 * against the event stream; a mismatch fails the run. A worker whose
 * cumulative budget is spent asks to be recycled instead of receiving
 * another assignment, and exits after sending this.
 *
 * @internal
 */
final readonly class Done implements Message
{
    /**
     * @param non-negative-int $peakMemoryBytes
     * @param list<TestId> $leaks
     */
    public function __construct(
        public ResultSummary $summary,
        public int $peakMemoryBytes,
        public ?CoverageMap $coverage = null,
        public array $leaks = [],
        public ?RecycleReason $wantsRecycle = null,
    ) {}

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
            'wantsRecycle' => $this->wantsRecycle?->value,
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
            ($reason = Wire::nullableString($payload, 'wantsRecycle')) === null || $reason === ''
                ? null
                : RecycleReason::from($reason),
        );
    }
}
