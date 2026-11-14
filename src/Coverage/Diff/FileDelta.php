<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Diff;

/**
 * Compares the coverage of one file in a baseline and a current map.
 *
 * A null percentage or uncovered-line count means that the applicable map did
 * not contain the file. delta() uses zero percent for an absent file.
 *
 * regressed() compares counts and percentages, not line numbers. Thus, a
 * change that moves lines does not cause a regression. Only a file in both
 * maps can regress. A rename gives a file in only one map on each side, so a
 * rename does not cause a regression.
 *
 * @internal
 */
final readonly class FileDelta
{
    /**
     * @var non-empty-string
     */
    public string $file;

    /**
     * @param int<0, max>|null $baselineUncoveredLines
     * @param int<0, max>|null $currentUncoveredLines
     */
    public function __construct(
        string $file,
        public ?float $baselinePercentage,
        public ?float $currentPercentage,
        public ?int $baselineUncoveredLines = null,
        public ?int $currentUncoveredLines = null,
    ) {
        if ($file === '') {
            throw new \InvalidArgumentException('Use a non-empty coverage delta file path.');
        }

        $this->file = $file;
    }

    public function delta(): float
    {
        return ($this->currentPercentage ?? 0.0) - ($this->baselinePercentage ?? 0.0);
    }

    public function isShared(): bool
    {
        return $this->baselinePercentage !== null && $this->currentPercentage !== null;
    }

    /**
     * Tells if the uncovered-line count increased or the percentage decreased.
     * The comparison rounds percentages to two decimal places.
     */
    public function regressed(): bool
    {
        if (!$this->isShared()) {
            return false;
        }

        if ($this->currentUncoveredLines !== null
            && $this->baselineUncoveredLines !== null
            && $this->currentUncoveredLines > $this->baselineUncoveredLines
        ) {
            return true;
        }

        return \round($this->currentPercentage, 2, \RoundingMode::HalfAwayFromZero)
            < \round($this->baselinePercentage, 2, \RoundingMode::HalfAwayFromZero);
    }

    /**
     * Tells if the uncovered-line count of a file in the current map changed.
     * A file that the baseline does not contain starts at zero uncovered lines.
     */
    public function uncoveredLinesChanged(): bool
    {
        return $this->currentUncoveredLines !== null
            && ($this->baselineUncoveredLines ?? 0) !== $this->currentUncoveredLines;
    }
}
