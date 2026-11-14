<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Diff;

/**
 * Contains changed, added, and removed files from a baseline comparison.
 * Unchanged files appear only when the caller supplies them directly.
 *
 * hasRegressions() examines only files that are in both maps. A file
 * regresses when its uncovered-line count increases or its percentage
 * decreases. An added or removed file cannot cause a regression. A renamed
 * file is a removed file and an added file.
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
