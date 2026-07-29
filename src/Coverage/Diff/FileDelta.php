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
     * @var non-empty-string
     */
    public string $file;

    /**
     * @var list<positive-int>
     */
    public array $newlyUncoveredLines;

    /**
     * @param list<int> $newlyUncoveredLines
     */
    public function __construct(
        string $file,
        public ?float $baselinePercentage,
        public ?float $currentPercentage,
        array $newlyUncoveredLines,
    ) {
        if ($file === '') {
            throw new \InvalidArgumentException('Use a non-empty coverage delta file path.');
        }

        foreach ($newlyUncoveredLines as $line) {
            if ($line < 1) {
                throw new \InvalidArgumentException(\sprintf(
                    'Use positive newly uncovered line numbers. Actual value: %d.',
                    $line,
                ));
            }
        }

        $this->file = $file;
        $this->newlyUncoveredLines = $newlyUncoveredLines;
    }

    public function delta(): float
    {
        return ($this->currentPercentage ?? 0.0) - ($this->baselinePercentage ?? 0.0);
    }
}
