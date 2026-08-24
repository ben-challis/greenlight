<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Coverage\CoverageGate;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Coverage\BranchCoverage;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\FunctionCoverage;
use Greenlight\Coverage\PathCoverage;
use Greenlight\Expect\Expect;

final class CoverageGateTest
{
    #[Test]
    public function percentageUsesTwoDecimalHalfUpRoundingAndLimitsAreInclusive(): void
    {
        $coverage = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [3])]);
        $configuration = new CoverageConfiguration([], null, [], 66.67, 1);

        Expect::that(CoverageGate::failures($configuration, $coverage))
            ->because('66.666 percent rounds to the inclusive 66.67-percent boundary')
            ->toBe([]);
    }

    #[Test]
    public function reportsEachFailedGateInStableOrder(): void
    {
        $coverage = new CoverageMap([new FileCoverage('/src/A.php', [1, 2], [3])]);
        $configuration = new CoverageConfiguration([], null, [], 66.68, 0);

        Expect::that(CoverageGate::failures($configuration, $coverage))
            ->because('all failed gates MUST be visible to the user')
            ->toBe([
                'Coverage gate failed: 66.67% is less than the minimum 66.68%.',
                'Coverage gate failed: 1 uncovered line exceeds the maximum 0.',
            ]);
    }

    #[Test]
    public function branchGatesRequireMeasuredBranchesAndUseTheSameInclusivePolicy(): void
    {
        $configuration = new CoverageConfiguration(
            [],
            null,
            [],
            minimumBranchPercentage: 50.0,
            maximumUncoveredBranches: 1,
        );

        Expect::that(CoverageGate::failures($configuration, CoverageMap::empty()))
            ->toBe(['Branch coverage gates require a branch coverage JSON version 2 result.']);

        $coverage = new CoverageMap([
            new FileCoverage('/src/A.php', [1], [], [
                new FunctionCoverage('run', [
                    new BranchCoverage(0, 1, 1, true),
                    new BranchCoverage(1, 1, 1, false),
                ], [
                    new PathCoverage([0], true),
                    new PathCoverage([2], false),
                ]),
            ]),
        ], true);

        Expect::that(CoverageGate::failures($configuration, $coverage))
            ->because('values equal to both branch limits MUST pass')
            ->toBe([]);
    }
}
