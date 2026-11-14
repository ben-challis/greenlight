<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Diff;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Diff\BaselineDiff;
use Greenlight\Coverage\FileCoverage;

use function Greenlight\expect;

final class BaselineDiffTest
{
    #[Test]
    public function comparingAMapAgainstItselfReportsNoChanges(): void
    {
        $map = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [3])]);

        $report = BaselineDiff::between($map, $map);

        expect($report->fileDeltas)->because('comparing a map against itself reports no changes')->toBe([]);
        expect($report->totalDelta())->toBe(0.0);
        expect($report->hasRegressions())->toBeFalse();
    }

    #[Test]
    public function reportsPerFileAndTotalPercentageDeltas(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/A.php', [1, 2, 3, 4], [])]);
        $current = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [3, 4])]);

        $report = BaselineDiff::between($baseline, $current);
        $delta = $report->fileDeltas['/src/A.php'];

        expect($delta->baselinePercentage)->because('reports per file and total percentage deltas')->toBe(100.0);
        expect($delta->currentPercentage)->toBe(50.0);
        expect($delta->delta())->toBeWithin(0.001, -50.0);
        expect($report->baselinePercentage)->toBe(100.0);
        expect($report->currentPercentage)->toBe(50.0);
        expect($report->totalDelta())->toBeWithin(0.001, -50.0);
        expect($report->hasRegressions())->toBeTrue();
    }

    #[Test]
    public function fileDeltasCountUncoveredLines(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [9])]);
        $current = new CoverageMap([new FileCoverage('/src/A.php', [1], [2, 5, 9])]);

        $delta = BaselineDiff::between($baseline, $current)->fileDeltas['/src/A.php'];

        expect($delta->baselineUncoveredLines)->because('the delta MUST count baseline uncovered lines')->toBe(1);
        expect($delta->currentUncoveredLines)->because('the delta MUST count current uncovered lines')->toBe(3);
        expect($delta->regressed())->toBeTrue();
    }

    #[Test]
    public function shiftedLinesWithHigherCoverageAreNotARegression(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/A.php', [3, 4], [5])]);
        $current = new CoverageMap([new FileCoverage('/src/A.php', [5, 6, 7], [8])]);

        $report = BaselineDiff::between($baseline, $current);

        expect($report->fileDeltas['/src/A.php']->currentPercentage)
            ->because('the shifted file has higher coverage')
            ->toBe(75.0);
        expect($report->hasRegressions())
            ->because('a line shift MUST NOT cause a regression when coverage rises')
            ->toBeFalse();
    }

    #[Test]
    public function aRenamedFileIsNotARegression(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/Old.php', [1], [2])]);
        $current = new CoverageMap([new FileCoverage('/src/New.php', [1], [2])]);

        $report = BaselineDiff::between($baseline, $current);

        expect(\array_keys($report->fileDeltas))
            ->because('a rename MUST appear as a removed file and an added file')
            ->toBe(['/src/New.php', '/src/Old.php']);
        expect($report->hasRegressions())
            ->because('a rename MUST NOT cause a regression')
            ->toBeFalse();
    }

    #[Test]
    public function filesOnlyInOneMapAppearWithANullSide(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/Gone.php', [1], [])]);
        $current = new CoverageMap([new FileCoverage('/src/New.php', [1], [2])]);

        $report = BaselineDiff::between($baseline, $current);

        expect(\array_keys($report->fileDeltas))->because('files only in one map appear with a null side')->toBe(['/src/Gone.php', '/src/New.php']);
        expect($report->fileDeltas['/src/Gone.php']->currentPercentage)->toBeNull();
        expect($report->fileDeltas['/src/New.php']->baselinePercentage)->toBeNull();
        expect($report->fileDeltas['/src/New.php']->currentUncoveredLines)->toBe(1);
        expect($report->hasRegressions())
            ->because('an added file MUST NOT cause a regression')
            ->toBeFalse();
    }

    #[Test]
    public function improvedCoverageIsNotARegression(): void
    {
        $baseline = new CoverageMap([new FileCoverage('/src/A.php', [1], [2])]);
        $current = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [])]);

        $report = BaselineDiff::between($baseline, $current);

        expect($report->totalDelta())->because('improved coverage is not a regression')->toBeWithin(0.001, 50.0);
        expect($report->hasRegressions())->toBeFalse();
    }

    #[Test]
    public function anOverallGainDoesNotHideAFileRegression(): void
    {
        $baseline = new CoverageMap([
            new FileCoverage('/src/A.php', [1, 2], [3]),
        ]);
        $current = new CoverageMap([
            new FileCoverage('/src/A.php', [1], [2, 3]),
            new FileCoverage('/src/B.php', [1, 2, 3, 4, 5, 6], []),
        ]);

        $report = BaselineDiff::between($baseline, $current);

        expect($report->totalDelta())
            ->because('the added covered file MUST produce an overall coverage gain')
            ->toBeGreaterThan(0.0);
        expect($report->hasRegressions())
            ->because('an overall gain MUST NOT hide a file regression')
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

        expect($report->fileDeltas['/src/A.php']->uncoveredLinesChanged())
            ->because('the retained file has the same uncovered-line count')
            ->toBeFalse();
        expect($report->hasRegressions())
            ->because('a percentage decrease in a retained file MUST remain a regression')
            ->toBeTrue();
    }
}
