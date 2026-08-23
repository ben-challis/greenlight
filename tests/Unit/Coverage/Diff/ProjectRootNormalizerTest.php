<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Diff;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Diff\BaselineDiff;
use Greenlight\Coverage\Diff\ProjectRootNormalizer;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final class ProjectRootNormalizerTest
{
    #[Test]
    public function differentCheckoutRootsProduceCommonComparisonPaths(): void
    {
        $baseline = ProjectRootNormalizer::normalize(
            new CoverageMap([new FileCoverage('/old/worktree/src/A.php', [1], [2])]),
            '/old/worktree/',
        );
        $current = ProjectRootNormalizer::normalize(
            new CoverageMap([new FileCoverage('/new/worktree/src/A.php', [1], [2])]),
            '/new/worktree',
        );

        Expect::that(\array_keys($baseline->files()))
            ->because('normalization MUST remove only the explicit checkout root')
            ->toBe(['src/A.php']);
        Expect::that(BaselineDiff::between($baseline, $current)->hasRegressions())
            ->because('the same project-relative file MUST compare across checkout roots')
            ->toBeFalse();
    }

    #[Test]
    public function aPathOutsideTheExplicitRootIsRejected(): void
    {
        $map = new CoverageMap([new FileCoverage('/dependency/A.php', [1], [])]);

        Expect::that(static fn(): CoverageMap => ProjectRootNormalizer::normalize($map, '/project'))
            ->because('partial path normalization can hide unmatched coverage files')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Coverage path "/dependency/A.php" is not below project root "/project".',
            );
    }

    #[Test]
    public function aRelativeProjectRootIsRejected(): void
    {
        Expect::that(static fn(): CoverageMap => ProjectRootNormalizer::normalize(CoverageMap::empty(), 'project'))
            ->because('normalization requires an explicit absolute checkout root')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'The project root must be an absolute path.',
            );
    }

    #[Test]
    public function relocationUsesTheTargetRootAndKeepsLineSets(): void
    {
        $relocated = ProjectRootNormalizer::relocate(
            new CoverageMap([new FileCoverage('/old/worktree/src/A.php', [2], [3])]),
            '/old/worktree',
            '/new/worktree/',
        );

        Expect::that($relocated->toWire())
            ->because('relocation MUST change only the project root')
            ->toBe([
                'files' => [
                    '/new/worktree/src/A.php' => [[2], [3]],
                ],
            ]);
    }
}
