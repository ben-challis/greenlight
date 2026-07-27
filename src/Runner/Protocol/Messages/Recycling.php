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
 * Tells the orchestrator that a worker reached a replacement limit.
 *
 * The listed entries remain incomplete and require reassignment. The worker
 * exits after it sends this message. The orchestrator compares the summary
 * to the event stream before it reassigns the remaining entries.
 *
 * @internal
 */
final readonly class Recycling implements Message
{
    /**
     * @param list<TestId> $remaining
     */
    public function __construct(
        public RecycleReason $reason,
        public array $remaining,
        public ResultSummary $summary,
        public ?CoverageMap $coverage = null,
    ) {}

    #[\Override]
    public static function tag(): string
    {
        return 'recycling';
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'reason' => $this->reason->value,
            'remaining' => \array_map(static fn(TestId $id): array => $id->toWire(), $this->remaining),
            'summary' => $this->summary->toWire(),
            'coverage' => $this->coverage?->toWire(),
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $coverage = Wire::nullableMap($payload, 'coverage');

        return new self(
            Wire::enum($payload, 'reason', RecycleReason::class),
            \array_map(TestId::fromWire(...), Wire::listOfMaps($payload, 'remaining')),
            ResultSummary::fromWire(Wire::map($payload, 'summary')),
            $coverage === null ? null : CoverageMap::fromWire($coverage),
        );
    }
}
