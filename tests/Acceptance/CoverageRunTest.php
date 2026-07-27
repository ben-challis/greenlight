<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\SubprocessCoverage;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final readonly class CoverageRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function collectsAndExportsCoverageThroughTheProcessPool(): void
    {
        $project = $this->writeProject();
        $outDir = $project->path('coverage-out');
        $result = $this->runIn($project, ['run', '--workers=2', '--reporter=plain'], 'coverage');

        Expect::that($result->exitCode)->because('collects and exports coverage through the process pool')->toBe(0)
            ->and($result->output())->toContain('Coverage: 60.00% (3 of 5 lines)')
            ->toContain('  json → coverage-out/coverage.json');

        $json = \file_get_contents($outDir . '/coverage.json');

        if ($json === false) {
            Fail::because(\sprintf(
                'Expected a readable coverage JSON export at "%s".',
                $outDir . '/coverage.json',
            ));
        }

        /** @var array{files: array<string, array{covered: list<int>, uncovered: list<int>}>} $decoded */
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $mathFile = null;

        foreach ($decoded['files'] as $file => $lines) {
            if (\str_ends_with($file, 'CoverageLib/Math.php')) {
                $mathFile = $lines;
            }
        }

        Expect::that($mathFile)->because('collects and exports coverage through the process pool')->not()->toBeNull()
            ->and($mathFile['covered'] ?? [])->not()->toHaveCount(0)
            ->and($mathFile['uncovered'] ?? [])->not()->toHaveCount(0);

        $lcov = \file_get_contents($outDir . '/lcov.info');

        Expect::that($lcov)->because('collects and exports coverage through the process pool')->toContain('SF:')
            ->toContain('end_of_record');
    }

    #[Test]
    public function missingDriverWarnsWithoutFailingTheRun(): void
    {
        $project = $this->writeProject();
        $result = $this->runIn($project, ['run', '--reporter=plain'], 'off');

        Expect::that($result->exitCode)->because('missing driver warns without failing the run')->toBe(0)
            ->and($result->output())->toContain('No worker collected the requested coverage')
            ->and(\is_dir($project->path('coverage-out')))->toBeFalse();
    }

    #[Test]
    public function unknownExportFormatFailsWithExactGuidance(): void
    {
        $project = $this->writeProject(exportFormat: 'sarif');
        $result = $this->runIn($project, ['run', '--reporter=plain'], 'coverage');

        Expect::that($result->exitCode)
            ->because('an unknown coverage export format MUST fail the run')
            ->toBe(1)
            ->and($result->output())
            ->toContain('Unknown coverage export format "sarif".')
            ->and(\is_dir($project->path('coverage-out')))
            ->toBeFalse();
    }

    #[Test]
    public function orchestratorProcessCoverageIsMergedIntoTheExport(): void
    {
        $project = $this->writeProject(includeOrchestrator: true);
        $outDir = $project->path('coverage-out');

        // A relay environment from an outer coverage run excludes orchestrator
        // coverage from this run. Clear it to make this run independent.
        $result = $this->runIn($project, ['run', '--workers=2', '--reporter=plain'], 'coverage', [
            SubprocessCoverage::DIRECTORY_ENV => '',
            SubprocessCoverage::INCLUDE_ENV => '',
        ]);

        Expect::that($result->exitCode)->because('orchestrator process coverage is merged into the export')->toBe(0)
            ->and($result->output())->toContain('  json → coverage-out/coverage.json');

        $json = \file_get_contents($outDir . '/coverage.json');

        if ($json === false) {
            Fail::because(\sprintf(
                'Expected a readable coverage JSON export at "%s".',
                $outDir . '/coverage.json',
            ));
        }

        /** @var array{files: array<string, array{covered: list<int>}>} $decoded */
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $orchestratorFile = null;

        foreach ($decoded['files'] as $file => $lines) {
            if (\str_ends_with($file, 'src/Runner/Orchestrator/Orchestrator.php')) {
                $orchestratorFile = $lines;
            }
        }

        // Only the orchestrator process loads Orchestrator.php. Thus, covered
        // lines in that file show orchestrator coverage collection.
        Expect::that($orchestratorFile)->because('orchestrator process coverage is merged into the export')->not()->toBeNull()
            ->and($orchestratorFile['covered'] ?? [])->not()->toHaveCount(0);
    }

    #[Test]
    public function spawnedCliProcessesDumpCoverageIntoTheSharedDirectory(): void
    {
        $root = \dirname(__DIR__, 2);
        $shared = $this->tempDirectory->subdirectory('coverage-relay');
        $project = $this->writeProject();

        $result = $this->runIn($project, ['run', '--workers=2', '--reporter=plain'], 'coverage', [
            SubprocessCoverage::DIRECTORY_ENV => $shared,
            SubprocessCoverage::INCLUDE_ENV => $root . '/src/Cli',
        ]);

        Expect::that($result->exitCode)->because('spawned CLI processes dump coverage into the shared directory')->toBe(0);

        $dumps = \glob($shared . '/*.json');
        $dumps = $dumps === false ? [] : $dumps;

        Expect::that($dumps)->because('spawned CLI processes dump coverage into the shared directory')->not()->toHaveCount(0);

        $contents = $dumps === [] ? '' : (string) \file_get_contents($dumps[0]);

        Expect::that($contents)->because('spawned CLI processes dump coverage into the shared directory')->toContain('src/Cli/Application.php');
    }

    #[Test]
    public function coverageDiffFailsOnRegressionsAndPassesWhenEqual(): void
    {
        $project = $this->writeProject();
        $outDir = $project->path('coverage-out');

        $result = $this->runIn($project, ['run', '--reporter=plain'], 'coverage');
        Expect::that($result->exitCode)->because('coverage diff fails on regressions and passes when equal')->toBe(0);

        $baseline = $outDir . '/coverage.json';

        $sameResult = $this->runIn(
            $project,
            ['coverage:diff', '--baseline=coverage-out/coverage.json', '--current=coverage-out/coverage.json'],
            'off',
        );

        Expect::that($sameResult->exitCode)->because('coverage diff fails on regressions and passes when equal')->toBe(0)
            ->and($sameResult->output())->toContain('(+0.00)');

        $json = \file_get_contents($baseline);

        if ($json === false) {
            Fail::because(\sprintf(
                'Expected a readable baseline coverage export at "%s".',
                $baseline,
            ));
        }

        /** @var array{files: array<string, array{covered: list<int>, uncovered: list<int>}>} $decoded */
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $mathFile = null;

        foreach (\array_keys($decoded['files']) as $file) {
            if (\str_ends_with($file, 'CoverageLib/Math.php')) {
                $mathFile = $file;
            }
        }

        if ($mathFile === null) {
            Fail::because('Baseline export has no entry for CoverageLib/Math.php.');
        }

        $before = $decoded['files'][$mathFile];

        // Make a regressed current export. Move one covered line to uncovered.
        $movedLine = $before['covered'][0];
        $decoded['files'][$mathFile]['covered'] = \array_values(\array_diff($before['covered'], [$movedLine]));
        $decoded['files'][$mathFile]['uncovered'] = [...$before['uncovered'], $movedLine];

        Expect::that($decoded['files'][$mathFile])->because('coverage diff fails on regressions and passes when equal')->not()->toBe($before);

        $regressed = \json_encode($decoded, \JSON_THROW_ON_ERROR);
        $regressedPath = $outDir . '/regressed.json';
        \file_put_contents($regressedPath, $regressed);

        $regressedResult = $this->runIn(
            $project,
            ['coverage:diff', '--baseline=coverage-out/coverage.json', '--current=coverage-out/regressed.json'],
            'off',
        );

        Expect::that($regressedResult->exitCode)->because('coverage diff fails on regressions and passes when equal')->toBe(1)
            ->and($regressedResult->output())->toContain('Coverage regressed against the baseline.')
            ->toContain('newly uncovered lines: ' . $movedLine);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $extraEnv
     */
    private function runIn(AcceptanceProject $project, array $arguments, string $xdebugMode, array $extraEnv = []): ProcessResult
    {
        return GreenlightCli::run(
            $project->directory,
            $arguments,
            ['XDEBUG_MODE' => $xdebugMode, ...$extraEnv],
        );
    }

    private function writeProject(
        bool $includeOrchestrator = false,
        ?string $exportFormat = null,
    ): AcceptanceProject {
        $root = \dirname(__DIR__, 2);
        $project = AcceptanceProject::create($this->tempDirectory, 'coverage');
        $orchestratorInclude = $includeOrchestrator
            ? \sprintf("\n        ->include(%s)", \var_export($root . '/src/Runner/Orchestrator', true))
            : '';
        $exports = $exportFormat === null
            ? "\n        ->export('json', 'coverage-out/coverage.json')"
                . "\n        ->export('lcov', 'coverage-out/lcov.info')"
            : \sprintf(
                "\n        ->export(%s, 'coverage-out/coverage.unknown')",
                \var_export($exportFormat, true),
            );

        $project->writeFile('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            return GreenlightConfig::create()
                ->paths([%s])
                ->coverage(fn($coverage) => $coverage
                    ->include(%s)%s%s);

            PHP,
            \var_export($root . '/tests/Fixture/CoverageSuite', true),
            \var_export($root . '/tests/Fixture/CoverageLib', true),
            $orchestratorInclude,
            $exports,
        ));

        return $project;
    }
}
