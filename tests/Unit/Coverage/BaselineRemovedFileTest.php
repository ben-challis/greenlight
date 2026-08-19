<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Diff\BaselineDiff;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final readonly class BaselineRemovedFileTest
{
    #[Test]
    public function removedSourceFilesAreNotCoverageRegressions(): void
    {
        $baseline = new CoverageMap([
            new FileCoverage('/src/Removed.php', [1], []),
        ]);
        $report = BaselineDiff::between($baseline, CoverageMap::empty());

        Expect::that($report->hasRegressions())
            ->because('removing a source file MUST NOT fail the coverage regression gate')
            ->toBeFalse();
    }

    #[Test]
    public function removedCoveredFileDoesNotTurnUnchangedCoverageIntoARegression(): void
    {
        $baseline = new CoverageMap([
            new FileCoverage('/src/Removed.php', [1], []),
            new FileCoverage('/src/Unchanged.php', [1], [2]),
        ]);
        $current = new CoverageMap([
            new FileCoverage('/src/Unchanged.php', [1], [2]),
        ]);

        $report = BaselineDiff::between($baseline, $current);

        Expect::that($report->totalDelta())
            ->because('displayed totals still describe the complete coverage maps')
            ->toBeLessThan(0.0);
        Expect::that($report->hasRegressions())
            ->because('a removed file MUST NOT make unchanged source coverage fail the regression gate')
            ->toBeFalse();
    }
}
