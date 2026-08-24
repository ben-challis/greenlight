<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Export;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\BranchCoverage;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\CloverExporter;
use Greenlight\Coverage\Export\CoberturaExporter;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\Export\LcovExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\FunctionCoverage;
use Greenlight\Coverage\PathCoverage;
use Greenlight\Expect\Expect;

final class BranchExporterTest
{
    #[Test]
    public function portableExportsReportMeasuredBranchData(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/Decision.php', [10], [], [
                new FunctionCoverage('decide', [
                    new BranchCoverage(0, 10, 10, true),
                    new BranchCoverage(1, 10, 11, false),
                ], [
                    new PathCoverage([0, 1], true),
                    new PathCoverage([1], false),
                ]),
            ]),
        ], true);

        $cobertura = new CoberturaExporter()->export($map)[CoberturaExporter::FILE_NAME];
        Expect::that($cobertura)
            ->toContain('branch-rate="0.5000"')
            ->toContain('branches-covered="1" branches-valid="2"')
            ->toContain('condition-coverage="50% (1/2)"');

        $clover = new CloverExporter()->export($map)[CloverExporter::FILE_NAME];
        Expect::that($clover)
            ->toContain('<line num="10" type="cond" count="1"/>')
            ->toContain('<line num="10" type="cond" count="0"/>')
            ->toContain('conditionals="2" coveredconditionals="1"');

        $lcov = new LcovExporter()->export($map)[LcovExporter::FILE_NAME];
        Expect::that($lcov)
            ->toContain("BRDA:10,0,0,1\n")
            ->toContain("BRDA:10,0,1,0\n")
            ->toContain("BRF:2\nBRH:1\n");

        $html = new HtmlExporter('/')->export($map)[HtmlExporter::INDEX_FILE_NAME];
        Expect::that($html)
            ->toContain('Branches')
            ->toContain('1/2')
            ->toContain('Paths');
    }
}
