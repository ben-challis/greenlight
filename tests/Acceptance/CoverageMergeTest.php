<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\BranchCoverage;
use Greenlight\Coverage\BranchExitCoverage;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\FunctionCoverage;
use Greenlight\Coverage\PathCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\CoverageJson;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CoverageMergeTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function mergesLineSetsAndWritesEachDeterministicExport(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-merge-exports');
        CoverageJson::write(
            $directory . '/shard-1.json',
            new CoverageMap([
                new FileCoverage('/project/src/B.php', [4], []),
                new FileCoverage('/project/src/A.php', [1], [2, 3]),
            ]),
        );
        CoverageJson::write(
            $directory . '/shard-2.json',
            new CoverageMap([
                new FileCoverage('/project/src/A.php', [2], [1, 3]),
            ]),
        );

        $result = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=shard-1.json',
            '--input=shard-2.json',
            '--export=json=out/coverage.json',
            '--export=lcov=out/lcov.info',
            '--export=clover=out/clover.xml',
            '--export=cobertura=out/cobertura.xml',
            '--export=html=out/html',
            '--no-ansi',
        ]);

        Expect::that($result->exitCode)
            ->because('compatible coverage inputs MUST produce all selected exports')
            ->toBe(0);
        Expect::that($result->output())
            ->toContain('Coverage: 75.00% (3 of 4 lines)')
            ->toContain('json → out/coverage.json')
            ->toContain('html → out/html');
        Expect::that(CoverageJson::read($directory . '/out/coverage.json')->toWire())
            ->toBe([
                'files' => [
                    '/project/src/A.php' => [[1, 2], [3]],
                    '/project/src/B.php' => [[4], []],
                ],
            ]);
        Expect::that((string) \file_get_contents($directory . '/out/lcov.info'))
            ->toContain("SF:/project/src/A.php\n")
            ->toContain("DA:3,0\n")
            ->toContain("SF:/project/src/B.php\n");
        Expect::that((string) \file_get_contents($directory . '/out/clover.xml'))
            ->toContain('<file name="/project/src/A.php">');
        Expect::that((string) \file_get_contents($directory . '/out/cobertura.xml'))
            ->toContain('filename="project/src/A.php"');
        Expect::that((string) \file_get_contents($directory . '/out/html/index.html'))
            ->toContain('src/A.php')
            ->toContain('src/B.php');

        $reverse = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=shard-2.json',
            '--input=shard-1.json',
            '--export=json=reverse.json',
            '--no-ansi',
        ]);

        Expect::that($reverse->exitCode)->toBe(0);
        Expect::that((string) \file_get_contents($directory . '/reverse.json'))
            ->because('input order MUST NOT change serialized coverage')
            ->toBe((string) \file_get_contents($directory . '/out/coverage.json'));
    }

    #[Test]
    public function mergesBranchAndPathHitsAcrossCiShards(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-merge-branches');
        CoverageJson::write($directory . '/shard-1.json', $this->branchMap(true, false));
        CoverageJson::write($directory . '/shard-2.json', $this->branchMap(false, true));

        $result = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=shard-1.json',
            '--input=shard-2.json',
            '--export=json=merged.json',
            '--export=cobertura=cobertura.xml',
            '--minimum-branch-coverage=100',
            '--maximum-uncovered-branches=0',
            '--no-ansi',
        ]);

        Expect::that($result->exitCode)
            ->because('CI shard merges MUST combine branch and path hit state. Output: ' . $result->output())
            ->toBe(0);

        $merged = CoverageJson::read($directory . '/merged.json');
        Expect::that([$merged->coveredBranchTotal(), $merged->branchTotal()])->toBe([2, 2]);
        Expect::that([$merged->coveredPathTotal(), $merged->pathTotal()])->toBe([2, 2]);
        Expect::that((string) \file_get_contents($directory . '/cobertura.xml'))
            ->toContain('branch-rate="1.0000"')
            ->toContain('branches-covered="2"')
            ->toContain('condition-coverage="100% (2/2)"');
    }

    #[Test]
    public function explicitRootsCreateOnePortableVersionOneMap(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-merge-roots');
        CoverageJson::write(
            $directory . '/one.json',
            new CoverageMap([new FileCoverage('/old/one/src/A.php', [1], [2])]),
        );
        CoverageJson::write(
            $directory . '/two.json',
            new CoverageMap([new FileCoverage('/old/two/src/A.php', [2], [1])]),
        );

        $result = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--input=two.json',
            '--input-root=/old/one',
            '--input-root=/old/two',
            '--project-root=/current/project',
            '--export=json=merged.json',
            '--no-ansi',
        ]);

        Expect::that($result->exitCode)
            ->because('explicit roots MUST map each shard to the selected project root')
            ->toBe(0);
        Expect::that(CoverageJson::read($directory . '/merged.json')->toWire())
            ->toBe([
                'files' => [
                    '/current/project/src/A.php' => [[1, 2], []],
                ],
            ]);
    }

    #[Test]
    public function duplicateInputsAndEmptyMapsDoNotChangeTheResult(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-merge-duplicates');
        CoverageJson::write($directory . '/empty.json', CoverageMap::empty());
        CoverageJson::write(
            $directory . '/coverage.json',
            new CoverageMap([new FileCoverage('/project/A.php', [1], [2])]),
        );

        $result = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=coverage.json',
            '--input=coverage.json',
            '--input=empty.json',
            '--export=json=merged.json',
            '--no-ansi',
        ]);

        Expect::that($result->exitCode)
            ->because('duplicate and empty inputs MUST be idempotent')
            ->toBe(0);
        Expect::that(CoverageJson::read($directory . '/merged.json')->toWire())
            ->toBe([
                'files' => [
                    '/project/A.php' => [[1], [2]],
                ],
            ]);
    }

    #[Test]
    public function mergesEmptyMapsIntoOneDeterministicEmptyDocument(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-merge-empty');
        CoverageJson::write($directory . '/one.json', CoverageMap::empty());
        CoverageJson::write($directory . '/two.json', CoverageMap::empty());

        $result = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--input=two.json',
            '--export=json=merged.json',
            '--no-ansi',
        ]);

        Expect::that($result->exitCode)
            ->because('empty shard maps MUST produce one valid empty map')
            ->toBe(0);
        Expect::that((string) \file_get_contents($directory . '/merged.json'))
            ->toBe('{"v":1,"files":{},"totals":{"files":0,"coveredLines":0,"executableLines":0,"percentage":100}}' . "\n");
    }

    #[Test]
    public function appliesCoverageGatesToTheMergedMapAfterItWritesExports(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-merge-gates');
        CoverageJson::write(
            $directory . '/one.json',
            new CoverageMap([new FileCoverage('/project/A.php', [1], [2])]),
        );
        CoverageJson::write($directory . '/two.json', CoverageMap::empty());

        $result = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--input=two.json',
            '--export=json=merged.json',
            '--minimum-coverage=50.01',
            '--maximum-uncovered-lines=0',
            '--no-ansi',
        ]);

        Expect::that($result->exitCode)
            ->because('a failed merged coverage gate MUST fail the command')
            ->toBe(1);
        Expect::that($result->output())
            ->toContain('Coverage gate failed: 50.00% is less than the minimum 50.01%.')
            ->toContain('Coverage gate failed: 1 uncovered line exceeds the maximum 0.');
        Expect::that(\is_file($directory . '/merged.json'))
            ->because('a failed gate MUST keep the merged coverage export')
            ->toBeTrue();
    }

    private function branchMap(bool $firstCovered, bool $secondCovered): CoverageMap
    {
        return new CoverageMap([
            new FileCoverage('/project/src/Decision.php', [10], [11], [
                new FunctionCoverage('decide', [
                    new BranchCoverage(0, 10, 10, $firstCovered, [
                        new BranchExitCoverage(0, $firstCovered),
                    ]),
                    new BranchCoverage(1, 10, 11, $secondCovered, [
                        new BranchExitCoverage(0, $secondCovered),
                    ]),
                ], [
                    new PathCoverage([0, 1], $firstCovered),
                    new PathCoverage([1], $secondCovered),
                ]),
            ]),
        ], true);
    }
}
