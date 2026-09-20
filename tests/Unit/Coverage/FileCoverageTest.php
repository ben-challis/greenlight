<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\FileCoverage;

use function Greenlight\expect;

final class FileCoverageTest
{
    #[Test]
    public function emptyFilePathsAreRejected(): void
    {
        // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        expect()->calling(static fn(): FileCoverage => new FileCoverage('', [], []))
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

        expect($merged->file)
            ->because('a zero-string coverage file path is not empty')
            ->toBe('0');
        expect($merged->coveredLines)
            ->because('coverage for the zero-string path MUST merge normally')
            ->toBe([1, 2]);
        expect($merged->percentage())
            ->toBe(100.0);
    }

    #[Test]
    public function lineListsAreSortedAndDeduplicated(): void
    {
        $file = new FileCoverage('/src/A.php', [9, 3, 3, 5], [12, 7, 12]);

        expect($file->coveredLines)->because('line lists are sorted and deduplicated')->toBe([3, 5, 9]);
        expect($file->uncoveredLines)->toBe([7, 12]);
    }

    #[Test]
    public function coveredWinsWhenALineAppearsInBothSets(): void
    {
        $file = new FileCoverage('/src/A.php', [3, 5], [3, 7]);

        expect($file->coveredLines)->because('covered wins when a line appears in both sets')->toBe([3, 5]);
        expect($file->uncoveredLines)->toBe([7]);
    }

    #[Test]
    public function lineHitsAreOrderedWithCoveredLinesSetToOne(): void
    {
        $file = new FileCoverage('/src/A.php', [9, 3, 5], [12, 3, 7]);

        expect($file->lineHits())
            ->because('line hits are canonical and covered wins')
            ->toBe([
                3 => 1,
                5 => 1,
                7 => 0,
                9 => 1,
                12 => 0,
            ]);
    }

    #[Test]
    public function percentageIsCoveredOverExecutable(): void
    {
        $file = new FileCoverage('/src/A.php', [1, 2, 3], [4]);

        expect($file->percentage())->because('percentage is covered over executable')->toBeWithin(0.001, 75.0);
        expect($file->executableLineCount())->toBe(4);
        expect($file->coveredLineCount())->toBe(3);
    }

    #[Test]
    public function fileWithoutExecutableLinesCountsAsFullyCovered(): void
    {
        expect(new FileCoverage('/src/A.php', [], [])->percentage())->because('file without executable lines counts as fully covered')->toBe(100.0);
    }

    #[Test]
    public function mergeUnionsCoverageAndCoveredWins(): void
    {
        $a = new FileCoverage('/src/A.php', [3], [5, 7]);
        $b = new FileCoverage('/src/A.php', [5], [3, 9]);

        $merged = $a->merge($b);

        expect($merged->coveredLines)->because('merge unions coverage and covered wins')->toBe([3, 5]);
        expect($merged->uncoveredLines)->toBe([7, 9]);
    }

    #[Test]
    public function mergingDifferentFilesIsRejected(): void
    {
        $a = new FileCoverage('/src/A.php', [1], []);
        $b = new FileCoverage('/src/B.php', [1], []);

        expect()->calling(static fn(): FileCoverage => $a->merge($b))->because('merging different files is rejected')
            ->toThrow(\LogicException::class, '/Cannot merge coverage of "\/src\/B\.php"/');
    }
}
