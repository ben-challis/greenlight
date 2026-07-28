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
}
