<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Diff;

/**
 * Contains changed, added, and removed files from a baseline comparison.
 * Unchanged files appear only when the caller supplies them directly.
 *
 * hasRegressions() reports a file in both maps that has more uncovered lines
 * or a lower percentage. It also reports an added file that has uncovered
 * lines. A removed file cannot cause a regression.
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
        return \array_any($this->fileDeltas, static fn(FileDelta $delta): bool => $delta->regressed());
    }
}
