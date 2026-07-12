<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\SubprocessCoverage;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Drives bin/greenlight with coverage enabled against a fixture project.
 *
 * Collection needs a driver, so runs are spawned with XDEBUG_MODE=coverage;
 * the no-driver branch is exercised with XDEBUG_MODE=off.
 */
final class CoverageRunTest
{
    private const string CONFIG_DIR = 'tests/Fixture/CoverageRunConfig';

    #[Test]
    public function collectsAndExportsCoverageThroughTheProcessPool(): void
    {
        $outDir = $this->outDir();
        $this->removeDir($outDir);

        try {
            [$exit, $output] = $this->runIn(['run', '--workers=2', '--reporter=plain'], 'coverage');

            Expect::that($exit)->toBe(0)
                ->and($output)->toContain('Coverage: 60.00% (3 of 5 lines)')
                ->and($output)->toContain('  json → coverage-out/coverage.json');

            $json = \file_get_contents($outDir . '/coverage.json');

            if ($json === false) {
                throw new \RuntimeException('The JSON export was not written.');
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
                ->and($lcov)->toContain('end_of_record');
        } finally {
            $this->removeDir($outDir);
        }
    }

    #[Test]
    public function missingDriverWarnsWithoutFailingTheRun(): void
    {
        $outDir = $this->outDir();
        $this->removeDir($outDir);

        try {
            [$exit, $output] = $this->runIn(['run', '--reporter=plain'], 'off');

            Expect::that($exit)->toBe(0)
                ->and($output)->toContain('Coverage was requested but no worker could collect it')
                ->and(\is_dir($outDir))->toBeFalse();
        } finally {
            $this->removeDir($outDir);
        }
    }

    #[Test]
    public function orchestratorProcessCoverageIsMergedIntoTheExport(): void
    {
        $configDir = 'tests/Fixture/CoverageOrchestratorConfig';
        $outDir = \dirname(__DIR__, 2) . '/' . $configDir . '/coverage-out';
        $this->removeDir($outDir);

        try {
            // A relay environment inherited from an outer coverage-enabled
            // suite run would suppress this run's own orchestrator collection
            // window; clear it so the run behaves as a standalone one.
            [$exit, $output] = $this->runIn(['run', '--workers=2', '--reporter=plain'], 'coverage', $configDir, [
                SubprocessCoverage::DIRECTORY_ENV => '',
                SubprocessCoverage::INCLUDE_ENV => '',
            ]);

            Expect::that($exit)->toBe(0)
                ->and($output)->toContain('  json → coverage-out/coverage.json');

            $json = \file_get_contents($outDir . '/coverage.json');

            if ($json === false) {
                throw new \RuntimeException('The JSON export was not written.');
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
        } finally {
            $this->removeDir($outDir);
        }
    }

    #[Test]
    public function spawnedCliProcessesDumpCoverageIntoTheSharedDirectory(): void
    {
        $root = \dirname(__DIR__, 2);
        $shared = \sys_get_temp_dir() . '/greenlight-relay-' . \bin2hex(\random_bytes(6));
        \mkdir($shared, 0o700, true);
        $outDir = $this->outDir();
        $this->removeDir($outDir);

        try {
            [$exit] = $this->runIn(['run', '--workers=2', '--reporter=plain'], 'coverage', extraEnv: [
                SubprocessCoverage::DIRECTORY_ENV => $shared,
                SubprocessCoverage::INCLUDE_ENV => $root . '/src/Cli',
            ]);

            Expect::that($exit)->toBe(0);

            $dumps = \glob($shared . '/*.json');
            $dumps = $dumps === false ? [] : $dumps;

            Expect::that($dumps)->not()->toHaveCount(0);

            $contents = $dumps === [] ? '' : (string) \file_get_contents($dumps[0]);

            Expect::that($contents)->toContain('src/Cli/Application.php');
        } finally {
            AcceptanceProject::removeTree($shared);
            $this->removeDir($outDir);
        }
    }

    #[Test]
    public function coverageDiffFailsOnRegressionsAndPassesWhenEqual(): void
    {
        $outDir = $this->outDir();
        $this->removeDir($outDir);

        try {
            [$exit] = $this->runIn(['run', '--reporter=plain'], 'coverage');
            Expect::that($exit)->toBe(0);

            $baseline = $outDir . '/coverage.json';

            [$sameExit, $sameOutput] = $this->runIn(
                ['coverage:diff', '--baseline=coverage-out/coverage.json', '--current=coverage-out/coverage.json'],
                'off',
            );

            Expect::that($sameExit)->toBe(0)
                ->and($sameOutput)->toContain('(+0.00)');

            $json = \file_get_contents($baseline);

            if ($json === false) {
                throw new \RuntimeException('Baseline export missing.');
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
                throw new \RuntimeException('Baseline export has no entry for CoverageLib/Math.php.');
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

            [$regressedExit, $regressedOutput] = $this->runIn(
                ['coverage:diff', '--baseline=coverage-out/coverage.json', '--current=coverage-out/regressed.json'],
                'off',
            );

            Expect::that($regressedExit)->toBe(1)
                ->and($regressedOutput)->toContain('Coverage regressed against the baseline.')
                ->and($regressedOutput)->toContain('newly uncovered lines: ' . $movedLine);
        } finally {
            $this->removeDir($outDir);
        }
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $extraEnv
     *
     * @return array{int, string}
     */
    private function runIn(array $arguments, string $xdebugMode, string $configDir = self::CONFIG_DIR, array $extraEnv = []): array
    {
        $root = \dirname(__DIR__, 2);

        return AcceptanceProject::runIn($root . '/' . $configDir, $arguments, ['XDEBUG_MODE' => $xdebugMode, ...$extraEnv]);
    }

    private function outDir(): string
    {
        return \dirname(__DIR__, 2) . '/' . self::CONFIG_DIR . '/coverage-out';
    }

    private function removeDir(string $directory): void
    {
        AcceptanceProject::removeTree($directory);
    }
}
