<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Diff\BaselineDiff;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final class BaselineDiffTest
{
    #[Test]
    public function comparingAMapAgainstItselfReportsNoChanges(): void
    {
        $map = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [3])]);

        $report = BaselineDiff::between($map, $map);

        new Expect()->that($report->fileDeltas)->toBe([])
            ->and($report->totalDelta())->toBe(0.0)
            ->and($report->hasRegressions())->toBeFalse();
    }

    #[Test]
    public function reportsPerFileAndTotalPercentageDeltas(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/A.php', [1, 2, 3, 4], [])]);
        $current = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [3, 4])]);

        $report = BaselineDiff::between($baseline, $current);
        $delta = $report->fileDeltas['/src/A.php'];

        new Expect()->that($delta->baselinePercentage)->toBe(100.0)
            ->and($delta->currentPercentage)->toBe(50.0)
            ->and($delta->delta())->toBeWithin(0.001, -50.0)
            ->and($report->baselinePercentage)->toBe(100.0)
            ->and($report->currentPercentage)->toBe(50.0)
            ->and($report->totalDelta())->toBeWithin(0.001, -50.0)
            ->and($report->hasRegressions())->toBeTrue();
    }

    #[Test]
    public function newlyUncoveredLinesAreLinesUncoveredNowButNotBefore(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [9])]);
        $current = new CoverageMap([new FileCoverage('/src/A.php', [1], [2, 5, 9])]);

        $report = BaselineDiff::between($baseline, $current);

        new Expect()->that($report->fileDeltas['/src/A.php']->newlyUncoveredLines)->toBe([2, 5]);
    }

    #[Test]
    public function filesOnlyInOneMapAppearWithANullSide(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/Gone.php', [1], [])]);
        $current = new CoverageMap([new FileCoverage('/src/New.php', [1], [2])]);

        $report = BaselineDiff::between($baseline, $current);

        new Expect()->that(\array_keys($report->fileDeltas))->toBe(['/src/Gone.php', '/src/New.php'])
            ->and($report->fileDeltas['/src/Gone.php']->currentPercentage)->toBeNull()
            ->and($report->fileDeltas['/src/New.php']->baselinePercentage)->toBeNull()
            ->and($report->fileDeltas['/src/New.php']->newlyUncoveredLines)->toBe([2]);
    }

    #[Test]
    public function improvedCoverageIsNotARegression(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/A.php', [1], [2])]);
        $current = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [])]);

        $report = BaselineDiff::between($baseline, $current);

        new Expect()->that($report->totalDelta())->toBeWithin(0.001, 50.0)
            ->and($report->hasRegressions())->toBeFalse();
    }
}
