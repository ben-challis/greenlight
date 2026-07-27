<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Diff;

/**
 * A null percentage means that the applicable map did not contain the file.
 * delta() uses zero percent for an absent file.
 *
 * The current map identifies a newly uncovered line as uncovered. The
 * baseline identifies the line as covered or not executable.
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
