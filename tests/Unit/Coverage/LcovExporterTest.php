<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\LcovExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final class LcovExporterTest
{
    #[Test]
    public function producesTheExactLcovTracefile(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/B.php', [2], []),
            new FileCoverage('/src/A.php', [3, 7], [5]),
        ]);

        $expected = <<<'LCOV'
            SF:/src/A.php
            DA:3,1
            DA:5,0
            DA:7,1
            LF:3
            LH:2
            end_of_record
            SF:/src/B.php
            DA:2,1
            LF:1
            LH:1
            end_of_record

            LCOV;

        new Expect()->that(new LcovExporter()->export($map))
            ->toBe([LcovExporter::FILE_NAME => $expected]);
    }

    #[Test]
    public function emptyMapProducesAnEmptyTracefile(): void
    {
        new Expect()->that(new LcovExporter()->export(CoverageMap::empty()))
            ->toBe([LcovExporter::FILE_NAME => '']);
    }
}
