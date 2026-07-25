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

/**
 * Drives bin/greenlight with coverage enabled against a fixture project.
 *
 * Collection needs a driver, so runs are spawned with XDEBUG_MODE=coverage;
 * the no-driver branch is exercised with XDEBUG_MODE=off.
 */
final readonly class CoverageRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function collectsAndExportsCoverageThroughTheProcessPool(): void
    {
        $project = $this->writeProject();
        $outDir = $project->path('coverage-out');
        $result = $this->runIn($project, ['run', '--workers=2', '--reporter=plain'], 'coverage');

        Expect::that($result->exitCode)->toBe(0)
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

        Expect::that($mathFile)->not()->toBeNull()
            ->and($mathFile['covered'] ?? [])->not()->toHaveCount(0)
            ->and($mathFile['uncovered'] ?? [])->not()->toHaveCount(0);

        $lcov = \file_get_contents($outDir . '/lcov.info');

        Expect::that($lcov)->toContain('SF:')
            ->toContain('end_of_record');
    }

    #[Test]
    public function missingDriverWarnsWithoutFailingTheRun(): void
    {
        $project = $this->writeProject();
        $result = $this->runIn($project, ['run', '--reporter=plain'], 'off');

        Expect::that($result->exitCode)->toBe(0)
            ->and($result->output())->toContain('Coverage was requested but no worker could collect it')
            ->and(\is_dir($project->path('coverage-out')))->toBeFalse();
    }

    #[Test]
    public function orchestratorProcessCoverageIsMergedIntoTheExport(): void
    {
        $project = $this->writeProject(includeOrchestrator: true);
        $outDir = $project->path('coverage-out');

        // A relay environment inherited from an outer coverage-enabled
        // suite run would suppress this run's own orchestrator collection
        // window; clear it so the run behaves as a standalone one.
        $result = $this->runIn($project, ['run', '--workers=2', '--reporter=plain'], 'coverage', [
            SubprocessCoverage::DIRECTORY_ENV => '',
            SubprocessCoverage::INCLUDE_ENV => '',
        ]);

        Expect::that($result->exitCode)->toBe(0)
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

        // Only the orchestrator process ever loads Orchestrator.php, so
        // covered lines in it prove orchestrator-side collection.
        Expect::that($orchestratorFile)->not()->toBeNull()
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

        Expect::that($result->exitCode)->toBe(0);

        $dumps = \glob($shared . '/*.json');
        $dumps = $dumps === false ? [] : $dumps;

        Expect::that($dumps)->not()->toHaveCount(0);

        $contents = $dumps === [] ? '' : (string) \file_get_contents($dumps[0]);

        Expect::that($contents)->toContain('src/Cli/Application.php');
    }

    #[Test]
    public function coverageDiffFailsOnRegressionsAndPassesWhenEqual(): void
    {
        $project = $this->writeProject();
        $outDir = $project->path('coverage-out');

        $result = $this->runIn($project, ['run', '--reporter=plain'], 'coverage');
        Expect::that($result->exitCode)->toBe(0);

        $baseline = $outDir . '/coverage.json';

        $sameResult = $this->runIn(
            $project,
            ['coverage:diff', '--baseline=coverage-out/coverage.json', '--current=coverage-out/coverage.json'],
            'off',
        );

        Expect::that($sameResult->exitCode)->toBe(0)
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

        // Fabricate a regressed current export: move one covered line to uncovered.
        $movedLine = $before['covered'][0];
        $decoded['files'][$mathFile]['covered'] = \array_values(\array_diff($before['covered'], [$movedLine]));
        $decoded['files'][$mathFile]['uncovered'] = [...$before['uncovered'], $movedLine];

        Expect::that($decoded['files'][$mathFile])->not()->toBe($before);

        $regressed = \json_encode($decoded, \JSON_THROW_ON_ERROR);
        $regressedPath = $outDir . '/regressed.json';
        \file_put_contents($regressedPath, $regressed);

        $regressedResult = $this->runIn(
            $project,
            ['coverage:diff', '--baseline=coverage-out/coverage.json', '--current=coverage-out/regressed.json'],
            'off',
        );

        Expect::that($regressedResult->exitCode)->toBe(1)
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

    private function writeProject(bool $includeOrchestrator = false): AcceptanceProject
    {
        $root = \dirname(__DIR__, 2);
        $project = AcceptanceProject::create($this->tempDirectory, 'coverage');
        $orchestratorInclude = $includeOrchestrator
            ? \sprintf("\n        ->include(%s)", \var_export($root . '/src/Runner/Orchestrator', true))
            : '';

        $project->write('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            return GreenlightConfig::create()
                ->paths([%s])
                ->coverage(fn($coverage) => $coverage
                    ->include(%s)%s
                    ->export('json', 'coverage-out/coverage.json')
                    ->export('lcov', 'coverage-out/lcov.info'));

            PHP,
            \var_export($root . '/tests/Fixture/CoverageSuite', true),
            \var_export($root . '/tests/Fixture/CoverageLib', true),
            $orchestratorInclude,
        ));

        return $project;
    }
}
