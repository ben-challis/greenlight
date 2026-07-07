<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * Drives bin/greenlight with coverage enabled against a fixture project.
 * Collection needs a driver, so runs are spawned with XDEBUG_MODE=coverage;
 * the no-driver branch is exercised with XDEBUG_MODE=off.
 */
final class CoverageRunTest
{
    private const string CONFIG_DIR = 'tests/Fixture/CoverageRunConfig';

    #[Test]
    public function collectsAndExportsCoverageThroughTheProcessPool(): void
    {
        $expect = new Expect();
        $outDir = $this->outDir();
        $this->removeDir($outDir);

        try {
            [$exit, $output] = $this->runIn(['run', '--workers=2', '--reporter=plain'], 'coverage');

            $expect->that($exit)->toBe(0)
                ->and($output)->toContain('Coverage: 60.00% of 5 executable lines')
                ->and($output)->toContain('wrote json to coverage-out/coverage.json');

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

            $expect->that($mathFile)->not()->toBeNull()
                ->and($mathFile['covered'] ?? [])->not()->toHaveCount(0)
                ->and($mathFile['uncovered'] ?? [])->not()->toHaveCount(0);

            $lcov = \file_get_contents($outDir . '/lcov.info');

            $expect->that($lcov)->toContain('SF:')
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

            new Expect()->that($exit)->toBe(0)
                ->and($output)->toContain('Coverage was requested but no worker could collect it')
                ->and(\is_dir($outDir))->toBeFalse();
        } finally {
            $this->removeDir($outDir);
        }
    }

    #[Test]
    public function coverageDiffFailsOnRegressionsAndPassesWhenEqual(): void
    {
        $expect = new Expect();
        $outDir = $this->outDir();
        $this->removeDir($outDir);

        try {
            [$exit] = $this->runIn(['run', '--reporter=plain'], 'coverage');
            $expect->that($exit)->toBe(0);

            $baseline = $outDir . '/coverage.json';

            [$sameExit, $sameOutput] = $this->runIn(
                ['coverage:diff', '--baseline=coverage-out/coverage.json', '--current=coverage-out/coverage.json'],
                'off',
            );

            $expect->that($sameExit)->toBe(0)
                ->and($sameOutput)->toContain('(+0.00)');

            $json = \file_get_contents($baseline);

            if ($json === false) {
                throw new \RuntimeException('Baseline export missing.');
            }

            // Fabricate a regressed current export: one covered line becomes uncovered.
            $regressed = \str_replace('"covered":[11,13,23],"uncovered":[18,20]', '"covered":[13,23],"uncovered":[11,18,20]', $json);
            $regressedPath = $outDir . '/regressed.json';
            \file_put_contents($regressedPath, $regressed);

            [$regressedExit, $regressedOutput] = $this->runIn(
                ['coverage:diff', '--baseline=coverage-out/coverage.json', '--current=coverage-out/regressed.json'],
                'off',
            );

            $expect->that($regressedExit)->toBe(1)
                ->and($regressedOutput)->toContain('Coverage regressed against the baseline.')
                ->and($regressedOutput)->toContain('newly uncovered lines: 11');
        } finally {
            $this->removeDir($outDir);
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function runIn(array $arguments, string $xdebugMode): array
    {
        $root = \dirname(__DIR__, 2);
        $parts = [\escapeshellarg(\PHP_BINARY), \escapeshellarg($root . '/bin/greenlight')];

        foreach ($arguments as $argument) {
            $parts[] = \escapeshellarg($argument);
        }

        $command = \sprintf(
            'cd %s && XDEBUG_MODE=%s %s 2>&1',
            \escapeshellarg($root . '/' . self::CONFIG_DIR),
            \escapeshellarg($xdebugMode),
            \implode(' ', $parts),
        );

        \exec($command, $output, $exit);

        return [$exit, \implode("\n", $output)];
    }

    private function outDir(): string
    {
        return \dirname(__DIR__, 2) . '/' . self::CONFIG_DIR . '/coverage-out';
    }

    private function removeDir(string $directory): void
    {
        if (!\is_dir($directory)) {
            return;
        }

        $files = \glob($directory . '/*');

        foreach ($files === false ? [] : $files as $file) {
            @\unlink($file);
        }

        @\rmdir($directory);
    }
}
