<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Diff;

/**
 * The coverage change of one file between a baseline and the current run.
 *
 * A null percentage on either side means the file was absent from that map;
 * delta() treats an absent side as zero percent.
 *
 * Newly uncovered lines are lines uncovered now that were not uncovered in
 * the baseline, either because they were covered or because they were not
 * executable then.
 *
 * @internal
 */
final readonly class FileDelta
{
    /**
     * @param non-empty-string $file
     * @param list<positive-int> $newlyUncoveredLines
     */
    public function __construct(
        public string $file,
        public ?float $baselinePercentage,
        public ?float $currentPercentage,
        public array $newlyUncoveredLines,
    ) {}

    public function delta(): float
    {
        return ($this->currentPercentage ?? 0.0) - ($this->baselinePercentage ?? 0.0);
    }
}
