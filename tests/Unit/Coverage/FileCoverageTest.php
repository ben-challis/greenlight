<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final class FileCoverageTest
{
    #[Test]
    public function lineListsAreSortedAndDeduplicated(): void
    {
        $file = new FileCoverage('/src/A.php', [9, 3, 3, 5], [12, 7, 12]);

        new Expect()->that($file->coveredLines)->toBe([3, 5, 9])
            ->and($file->uncoveredLines)->toBe([7, 12]);
    }

    #[Test]
    public function coveredWinsWhenALineAppearsInBothSets(): void
    {
        $file = new FileCoverage('/src/A.php', [3, 5], [3, 7]);

        new Expect()->that($file->coveredLines)->toBe([3, 5])
            ->and($file->uncoveredLines)->toBe([7]);
    }

    #[Test]
    public function percentageIsCoveredOverExecutable(): void
    {
        $file = new FileCoverage('/src/A.php', [1, 2, 3], [4]);

        new Expect()->that($file->percentage())->toBeWithin(0.001, 75.0)
            ->and($file->executableLineCount())->toBe(4)
            ->and($file->coveredLineCount())->toBe(3);
    }

    #[Test]
    public function fileWithoutExecutableLinesCountsAsFullyCovered(): void
    {
        new Expect()->that(new FileCoverage('/src/A.php', [], [])->percentage())->toBe(100.0);
    }

    #[Test]
    public function mergeUnionsCoverageAndCoveredWins(): void
    {
        $a = new FileCoverage('/src/A.php', [3], [5, 7]);
        $b = new FileCoverage('/src/A.php', [5], [3, 9]);

        $merged = $a->merge($b);

        new Expect()->that($merged->coveredLines)->toBe([3, 5])
            ->and($merged->uncoveredLines)->toBe([7, 9]);
    }

    #[Test]
    public function mergingDifferentFilesIsRejected(): void
    {
        $a = new FileCoverage('/src/A.php', [1], []);
        $b = new FileCoverage('/src/B.php', [1], []);

        new Expect()->that(static fn(): FileCoverage => $a->merge($b))
            ->toThrow(\LogicException::class, '/Cannot merge coverage of "\/src\/B\.php"/');
    }

    #[Test]
    public function nonPositiveLineNumbersAreRejected(): void
    {
        new Expect()->that(static fn(): FileCoverage => new FileCoverage('/src/A.php', [0], []))
            ->toThrow(\InvalidArgumentException::class, '/must be positive/');
    }
}
