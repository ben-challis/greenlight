<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Diff;

/**
 * Omits files with no percentage change and no new uncovered lines.
 * hasRegressions() compares the total coverage of files that are in both maps.
 * Thus, a removed file cannot cause a regression.
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
        private float $sharedBaselinePercentage,
        private float $sharedCurrentPercentage,
    ) {}

    public function totalDelta(): float
    {
        return $this->currentPercentage - $this->baselinePercentage;
    }

    public function hasRegressions(): bool
    {
        if ($this->sharedCurrentPercentage < $this->sharedBaselinePercentage) {
            return true;
        }
        return \array_any($this->fileDeltas, static fn(FileDelta $delta): bool => $delta->newlyUncoveredLines !== []);
    }
}
