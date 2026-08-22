<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Diff;

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

        Expect::that($report->fileDeltas)->because('comparing a map against itself reports no changes')->toBe([]);
        Expect::that($report->totalDelta())->toBe(0.0);
        Expect::that($report->hasRegressions())->toBeFalse();
    }

    #[Test]
    public function reportsPerFileAndTotalPercentageDeltas(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/A.php', [1, 2, 3, 4], [])]);
        $current = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [3, 4])]);

        $report = BaselineDiff::between($baseline, $current);
        $delta = $report->fileDeltas['/src/A.php'];

        Expect::that($delta->baselinePercentage)->because('reports per file and total percentage deltas')->toBe(100.0);
        Expect::that($delta->currentPercentage)->toBe(50.0);
        Expect::that($delta->delta())->toBeWithin(0.001, -50.0);
        Expect::that($report->baselinePercentage)->toBe(100.0);
        Expect::that($report->currentPercentage)->toBe(50.0);
        Expect::that($report->totalDelta())->toBeWithin(0.001, -50.0);
        Expect::that($report->hasRegressions())->toBeTrue();
    }

    #[Test]
    public function newlyUncoveredLinesAreLinesUncoveredNowButNotBefore(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [9])]);
        $current = new CoverageMap([new FileCoverage('/src/A.php', [1], [2, 5, 9])]);

        $report = BaselineDiff::between($baseline, $current);

        Expect::that($report->fileDeltas['/src/A.php']->newlyUncoveredLines)->because('newly uncovered lines are lines uncovered now but not before')->toBe([2, 5]);
    }

    #[Test]
    public function filesOnlyInOneMapAppearWithANullSide(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/Gone.php', [1], [])]);
        $current = new CoverageMap([new FileCoverage('/src/New.php', [1], [2])]);

        $report = BaselineDiff::between($baseline, $current);

        Expect::that(\array_keys($report->fileDeltas))->because('files only in one map appear with a null side')->toBe(['/src/Gone.php', '/src/New.php']);
        Expect::that($report->fileDeltas['/src/Gone.php']->currentPercentage)->toBeNull();
        Expect::that($report->fileDeltas['/src/New.php']->baselinePercentage)->toBeNull();
        Expect::that($report->fileDeltas['/src/New.php']->newlyUncoveredLines)->toBe([2]);
    }

    #[Test]
    public function improvedCoverageIsNotARegression(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/A.php', [1], [2])]);
        $current = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [])]);

        $report = BaselineDiff::between($baseline, $current);

        Expect::that($report->totalDelta())->because('improved coverage is not a regression')->toBeWithin(0.001, 50.0);
        Expect::that($report->hasRegressions())->toBeFalse();
    }

    #[Test]
    public function anOverallGainDoesNotHideANewlyUncoveredLine(): void
    {
        $baseline = new CoverageMap([
            new FileCoverage('/src/A.php', [1], [2]),
        ]);
        $current = new CoverageMap([
            new FileCoverage('/src/A.php', [2], [1]),
            new FileCoverage('/src/B.php', [1, 2, 3, 4], []),
        ]);

        $report = BaselineDiff::between($baseline, $current);

        Expect::that($report->totalDelta())
            ->because('the added covered file MUST produce an overall coverage gain')
            ->toBeGreaterThan(0.0);
        Expect::that($report->fileDeltas['/src/A.php']->newlyUncoveredLines)
            ->because('the diff MUST preserve the line-level regression')
            ->toBe([1]);
        Expect::that($report->hasRegressions())
            ->because('an overall gain MUST NOT hide a newly uncovered line')
            ->toBeTrue();
    }

    #[Test]
    public function retainedFilePercentageDecreaseRemainsARegression(): void
    {
        $baseline = new CoverageMap([
            new FileCoverage('/src/A.php', [1, 2], [3]),
        ]);
        $current = new CoverageMap([
            new FileCoverage('/src/A.php', [1], [3]),
        ]);

        $report = BaselineDiff::between($baseline, $current);

        Expect::that($report->fileDeltas['/src/A.php']->newlyUncoveredLines)
            ->because('the retained file has no newly uncovered line')
            ->toBe([]);
        Expect::that($report->hasRegressions())
            ->because('a total coverage decrease in retained files MUST remain a regression')
            ->toBeTrue();
    }
}
