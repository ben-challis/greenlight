<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Wire\Wire;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Runner\Protocol\Message;

/**
 * Worker to orchestrator: slice complete. The summary is cross-checked
 * against the event stream; a mismatch fails the run.
 *
 * @internal
 */
final readonly class Done implements Message
{
    /**
     * @param non-negative-int $peakMemoryBytes
     */
    public function __construct(
        public ResultSummary $summary,
        public int $peakMemoryBytes,
        public ?CoverageMap $coverage = null,
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
        );
    }
}
