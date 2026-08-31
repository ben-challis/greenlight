<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\CoverageJson;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CoverageDiffOutputTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function removedUncoveredFilesDoNotProduceNeutralFileDeltas(): void
    {
        $directory = $this->tempDirectory->subdirectory('neutral-file-delta');
        $kept = new FileCoverage('/project/src/Kept.php', [1], []);
        CoverageJson::write(
            $directory . '/baseline.json',
            new CoverageMap([
                $kept,
                new FileCoverage('/project/src/Removed.php', [], [1]),
            ]),
        );
        CoverageJson::write(
            $directory . '/current.json',
            new CoverageMap([$kept]),
        );

        $result = GreenlightCli::run(
            $directory,
            [
                'coverage:diff',
                '--baseline=baseline.json',
                '--current=current.json',
            ],
        );

        Expect::that($result->exitCode)
            ->because('removing an uncovered file MUST NOT fail the coverage diff')
            ->toBe(0);
        Expect::that($result->output())
            ->because('a removed zero-percent file has no useful file-level delta')
            ->toBe('Coverage: baseline 50.00%, current 100.00% (+50.00)');
    }

    #[Test]
    public function explicitProjectRootsCompareDifferentCheckoutLocations(): void
    {
        $directory = $this->tempDirectory->subdirectory('portable-root-delta');
        CoverageJson::write(
            $directory . '/baseline.json',
            new CoverageMap([new FileCoverage('/ci/baseline/src/A.php', [1, 2], [])]),
        );
        CoverageJson::write(
            $directory . '/current.json',
            new CoverageMap([new FileCoverage('/workspace/current/src/A.php', [1], [2])]),
        );

        $result = GreenlightCli::run(
            $directory,
            [
                'coverage:diff',
                '--baseline=baseline.json',
                '--current=current.json',
                '--baseline-root=/ci/baseline',
                '--current-root=/workspace/current',
            ],
        );

        Expect::that($result->exitCode)
            ->because('explicit roots MUST compare matching project-relative files')
            ->toBe(1);
        Expect::that($result->stdoutLines())
            ->because('portable comparison output MUST use the normalized project-relative path')
            ->toBe([
                'Coverage: baseline 100.00%, current 50.00% (-50.00)',
                'src/A.php: 100.00% -> 50.00% (-50.00), newly uncovered lines: 2',
            ]);
    }

    #[Test]
    public function currentCoverageMustPassEachCommandLineGate(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-diff-gates');
        $map = new CoverageMap([new FileCoverage('/project/src/A.php', [1], [2])]);
        CoverageJson::write($directory . '/baseline.json', $map);
        CoverageJson::write($directory . '/current.json', $map);

        $passing = GreenlightCli::run($directory, [
            'coverage:diff',
            '--baseline=baseline.json',
            '--current=current.json',
            '--minimum-coverage=50.00',
            '--maximum-uncovered-lines=1',
        ]);

        Expect::that($passing->exitCode)
            ->because('the current report equals both inclusive limits')
            ->toBe(0);

        $failing = GreenlightCli::run($directory, [
            'coverage:diff',
            '--baseline=baseline.json',
            '--current=current.json',
            '--minimum-coverage=50.01',
            '--maximum-uncovered-lines=0',
        ]);

        Expect::that($failing->exitCode)
            ->because('a current report that fails a gate MUST fail coverage:diff')
            ->toBe(1);
        Expect::that($failing->output())
            ->toContain('Coverage gate failed: 50.00% is less than the minimum 50.01%.')
            ->toContain('Coverage gate failed: 1 uncovered line exceeds the maximum 0.')
            ->not()->toContain('Coverage regressed against the baseline.');
    }
}
