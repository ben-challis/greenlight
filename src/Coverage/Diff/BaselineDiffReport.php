<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Diff;

/**
 * Omits files whose percentage is unchanged and which gained no uncovered
 * lines.
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
