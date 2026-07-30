<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final class FileCoverageTest
{
    #[Test]
    public function emptyFilePathsAreRejected(): void
    {
        Expect::that(static fn(): FileCoverage => new FileCoverage('', [], []))
            ->because('coverage entries MUST identify a file')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use a non-empty coverage file path.',
            );
    }

    #[Test]
    public function zeroStringFilePathSurvivesMergeAndCalculation(): void
    {
        $first = new FileCoverage('0', [1], [2]);
        $second = new FileCoverage('0', [2], []);
        $merged = $first->merge($second);

        Expect::that($merged->file)
            ->because('a zero-string coverage file path is not empty')
            ->toBe('0')
            ->and($merged->coveredLines)
            ->because('coverage for the zero-string path MUST merge normally')
            ->toBe([1, 2])
            ->and($merged->percentage())
            ->toBe(100.0);
    }

    #[Test]
    public function lineListsAreSortedAndDeduplicated(): void
    {
        $file = new FileCoverage('/src/A.php', [9, 3, 3, 5], [12, 7, 12]);

        Expect::that($file->coveredLines)->because('line lists are sorted and deduplicated')->toBe([3, 5, 9])
            ->and($file->uncoveredLines)->toBe([7, 12]);
    }

    #[Test]
    public function coveredWinsWhenALineAppearsInBothSets(): void
    {
        $file = new FileCoverage('/src/A.php', [3, 5], [3, 7]);

        Expect::that($file->coveredLines)->because('covered wins when a line appears in both sets')->toBe([3, 5])
            ->and($file->uncoveredLines)->toBe([7]);
    }

    #[Test]
    public function percentageIsCoveredOverExecutable(): void
    {
        $file = new FileCoverage('/src/A.php', [1, 2, 3], [4]);

        Expect::that($file->percentage())->because('percentage is covered over executable')->toBeWithin(0.001, 75.0)
            ->and($file->executableLineCount())->toBe(4)
            ->and($file->coveredLineCount())->toBe(3);
    }

    #[Test]
    public function fileWithoutExecutableLinesCountsAsFullyCovered(): void
    {
        Expect::that(new FileCoverage('/src/A.php', [], [])->percentage())->because('file without executable lines counts as fully covered')->toBe(100.0);
    }

    #[Test]
    public function mergeUnionsCoverageAndCoveredWins(): void
    {
        $a = new FileCoverage('/src/A.php', [3], [5, 7]);
        $b = new FileCoverage('/src/A.php', [5], [3, 9]);

        $merged = $a->merge($b);

        Expect::that($merged->coveredLines)->because('merge unions coverage and covered wins')->toBe([3, 5])
            ->and($merged->uncoveredLines)->toBe([7, 9]);
    }

    #[Test]
    public function mergingDifferentFilesIsRejected(): void
    {
        $a = new FileCoverage('/src/A.php', [1], []);
        $b = new FileCoverage('/src/B.php', [1], []);

        Expect::that(static fn(): FileCoverage => $a->merge($b))->because('merging different files is rejected')
            ->toThrow(\LogicException::class, '/Cannot merge coverage of "\/src\/B\.php"/');
    }

    #[Test]
    public function nonPositiveLineNumbersAreRejected(): void
    {
        Expect::that(static fn(): FileCoverage => new FileCoverage('/src/A.php', [0], []))->because('nonpositive line numbers are rejected')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use positive coverage line numbers. Actual value: 0.',
            );
    }
}
