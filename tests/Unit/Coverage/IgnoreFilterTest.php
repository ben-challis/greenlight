<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\Ignore\IgnoreFilter;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

final class IgnoreFilterTest
{
    #[Test]
    public function subtractsIgnoredLinesFromBothSets(): void
    {
        $directory = new TempDirectory();

        try {
            $path = $directory->path() . '/subject.php';
            \file_put_contents($path, <<<'PHP'
                <?php
                $a = 1;
                // @codeCoverageIgnoreStart
                $b = 2;
                $c = 3;
                // @codeCoverageIgnoreEnd
                $d = 4;

                PHP);

            $map = new CoverageMap([new FileCoverage($path, [2, 4], [5, 7])]);

            $filtered = new IgnoreFilter()->apply($map);
            $file = $filtered->files()[$path];

            Expect::that($file->coveredLines)->toBe([2])
                ->and($file->uncoveredLines)->toBe([7]);
        } finally {
            $directory->dispose();
        }
    }

    #[Test]
    public function fullyIgnoredFilesAreDroppedFromTheMap(): void
    {
        $directory = new TempDirectory();

        try {
            $path = $directory->path() . '/gone.php';
            \file_put_contents($path, <<<'PHP'
                <?php
                // @codeCoverageIgnoreStart
                $a = 1;
                $b = 2;

                PHP);

            $map = new CoverageMap([new FileCoverage($path, [3], [4])]);

            Expect::that(new IgnoreFilter()->apply($map)->isEmpty())->toBeTrue();
        } finally {
            $directory->dispose();
        }
    }

    #[Test]
    public function filesWithoutMarkersPassThroughUnchanged(): void
    {
        $map = new CoverageMap([new FileCoverage('/nonexistent/plain.php', [1, 2], [3])]);

        $filtered = new IgnoreFilter()->apply($map);
        $file = $filtered->files()['/nonexistent/plain.php'];

        Expect::that($file->coveredLines)->toBe([1, 2])
            ->and($file->uncoveredLines)->toBe([3]);
    }

    #[Test]
    public function emptyMapStaysEmpty(): void
    {
        Expect::that(new IgnoreFilter()->apply(CoverageMap::empty())->isEmpty())->toBeTrue();
    }
}
