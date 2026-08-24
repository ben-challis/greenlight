<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\BranchCoverage;
use Greenlight\Coverage\BranchExitCoverage;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\FunctionCoverage;
use Greenlight\Coverage\PathCoverage;
use Greenlight\Expect\Expect;

final class BranchCoverageTest
{
    #[Test]
    public function mergeKeepsBranchAndPathIdentityAndCombinesHitState(): void
    {
        $uncovered = $this->map(false);
        $covered = $this->map(true);

        $merged = $uncovered->merge($covered);

        Expect::that($merged->toWire())
            ->because('branch merges MUST retain deterministic identity and combine hit state')
            ->toBe($covered->toWire());
        Expect::that([
            $merged->coveredBranchTotal(),
            $merged->branchTotal(),
            $merged->coveredPathTotal(),
            $merged->pathTotal(),
        ])->toBe([1, 1, 1, 1]);
    }

    #[Test]
    public function mergeRejectsLineOnlyAndBranchCoverage(): void
    {
        Expect::that(fn(): CoverageMap => CoverageMap::empty()->merge(CoverageMap::empty(true)))
            ->because('a merge MUST NOT imply that line-only data measured branches')
            ->toThrow(
                \LogicException::class,
                message: 'Cannot merge line-only coverage with branch coverage.',
            );
    }

    #[Test]
    public function mergeRejectsConflictingBranchMetadata(): void
    {
        $left = $this->map(false);
        $right = new CoverageMap([
            new FileCoverage('/src/Decision.php', [10], [], [
                new FunctionCoverage('decide', [
                    new BranchCoverage(0, 10, 12, false),
                ], [
                    new PathCoverage([0], false),
                ]),
            ]),
        ], true);

        Expect::that(fn(): CoverageMap => $left->merge($right))
            ->because('a branch identity MUST have stable source metadata')
            ->toThrow(\LogicException::class, message: 'Branch metadata differs for branch 0.');
    }

    private function map(bool $covered): CoverageMap
    {
        return new CoverageMap([
            new FileCoverage('/src/Decision.php', [10], [11], [
                new FunctionCoverage('decide', [
                    new BranchCoverage(0, 10, 11, $covered, [
                        new BranchExitCoverage(0, $covered),
                    ]),
                ], [
                    new PathCoverage([0], $covered),
                ]),
            ]),
        ], true);
    }
}
