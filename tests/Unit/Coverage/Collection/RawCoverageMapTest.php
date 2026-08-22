<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Collection;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Collection\PathFilter;
use Greenlight\Coverage\Collection\RawCoverage;
use Greenlight\Expect\Expect;

final readonly class RawCoverageMapTest
{
    #[Test]
    public function conversionSplitsStatusesAndDropsDeadCode(): void
    {
        $map = new RawCoverage([
            '/src/A.php' => [3 => 1, 4 => -1, 5 => -2, 6 => 7],
        ])->toMap();

        $file = $map->files()['/src/A.php'];

        Expect::that($file->coveredLines)->because('conversion splits statuses and drops dead code')->toBe([3, 6]);
        Expect::that($file->uncoveredLines)->toBe([4]);
    }

    #[Test]
    public function conversionAppliesThePathFilter(): void
    {
        $raw = new RawCoverage([
            '/project/src/A.php' => [1 => 1],
            '/project/vendor/dep/B.php' => [1 => 1],
        ]);

        $map = $raw->toMap(new PathFilter(['/project/src']));

        Expect::that(\array_keys($map->files()))->because('conversion applies the path filter')->toBe(['/project/src/A.php']);
    }

    #[Test]
    public function conversionDropsFilesWithNoExecutableLines(): void
    {
        $map = new RawCoverage(['/src/A.php' => [3 => -2]])->toMap();

        Expect::that($map->isEmpty())->because('conversion drops files with no executable lines')->toBeTrue();
    }

    #[Test]
    public function conversionDropsInvalidLineNumbers(): void
    {
        $map = new RawCoverage([
            '/src/A.php' => [-1 => 1, 0 => -1, 1 => 1],
        ])->toMap();

        Expect::that($map->files()['/src/A.php']->coveredLines)
            ->because('raw coverage drops non-positive line numbers')
            ->toBe([1]);
        Expect::that($map->files()['/src/A.php']->uncoveredLines)->toBe([]);
    }

    #[Test]
    public function conversionDropsUnusableDriverEntries(): void
    {
        $map = new RawCoverage([
            '' => [1 => 1],
            '/src/A.php' => [1 => 0, 2 => -2, 3 => 1, 4 => -1],
        ])->toMap();

        Expect::that($map->toWire())
            ->because('raw coverage MUST keep only usable paths and documented driver statuses')
            ->toBe([
                'files' => [
                    '/src/A.php' => [[3], [4]],
                ],
            ]);
    }
}
