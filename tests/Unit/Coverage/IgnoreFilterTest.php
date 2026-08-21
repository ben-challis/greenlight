<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\Ignore\IgnoreFilter;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class IgnoreFilterTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function subtractsIgnoredLinesFromBothSets(): void
    {
        $path = $this->tempDirectory->subdirectory('subtracts-ignored-lines') . '/subject.php';
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

        Expect::that($file->coveredLines)->toBe([2]);
        Expect::that($file->uncoveredLines)->toBe([7]);
    }

    #[Test]
    public function fullyIgnoredFilesAreDroppedFromTheMap(): void
    {
        $path = $this->tempDirectory->subdirectory('fully-ignored-files') . '/gone.php';
        \file_put_contents($path, <<<'PHP'
            <?php
            // @codeCoverageIgnoreStart
            $a = 1;
            $b = 2;

            PHP);

        $map = new CoverageMap([new FileCoverage($path, [3], [4])]);

        Expect::that(new IgnoreFilter()->apply($map)->isEmpty())->toBeTrue();
    }

    #[Test]
    public function filesWithoutMarkersPassThroughUnchanged(): void
    {
        $map = new CoverageMap([new FileCoverage('/nonexistent/plain.php', [1, 2], [3])]);

        $filtered = new IgnoreFilter()->apply($map);
        $file = $filtered->files()['/nonexistent/plain.php'];

        Expect::that($file->coveredLines)->because('files without markers remain unchanged')->toBe([1, 2]);
        Expect::that($file->uncoveredLines)->toBe([3]);
    }

    #[Test]
    public function emptyMapStaysEmpty(): void
    {
        Expect::that(new IgnoreFilter()->apply(CoverageMap::empty())->isEmpty())->because('empty map stays empty')->toBeTrue();
    }
}
