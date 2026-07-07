<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Diff;

/**
 * The full result of comparing a coverage baseline against the current run.
 *
 * It carries one delta per changed file plus total percentages on both sides.
 * Files whose percentage is unchanged and which gained no uncovered lines are
 * omitted from the per-file list.
 *
 * @internal
 */
final readonly class BaselineDiffReport
{
    /**
     * @param array<non-empty-string, FileDelta> $fileDeltas keyed by file path, sorted by path
     */
    public function __construct(
        public array $fileDeltas,
        public float $baselinePercentage,
        public float $currentPercentage,
    ) {}

    public function totalDelta(): float
    {
        return $this->currentPercentage - $this->baselinePercentage;
    }

    public function hasRegressions(): bool
    {
        if ($this->totalDelta() < 0.0) {
            return true;
        }
        return \array_any($this->fileDeltas, static fn(FileDelta $delta): bool => $delta->newlyUncoveredLines !== []);
    }
}
