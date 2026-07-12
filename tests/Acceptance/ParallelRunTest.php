<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Core\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Drives bin/greenlight with a process pool against fixture projects and
 * asserts on observable behaviour: exit codes and summary lines.
 *
 * Crash and hang fixtures must only ever run through here, never in-process.
 */
final class ParallelRunTest
{
    #[Test]
    public function parallelResultsMatchSequentialResults(): void
    {
        // A private copy of ListTestsConfig, so this comparison run cannot
        // race another acceptance test's use of the same working directory.
        $project = AcceptanceProject::copyOfListTestsConfig('parallel');

        try {
            [$sequentialExit, $sequential] = $project->run('run', '--workers=1');
            [$parallelExit, $parallel] = $project->run('run', '--workers=3');

            Expect::that($sequentialExit)->toBe(0)
                ->and($parallelExit)->toBe(0)
                ->and($this->summaryLine($sequential))->toBe('7 tests, 7 passed, 0 expectations')
                ->and($this->summaryLine($parallel))->toBe('7 tests, 7 passed, 0 expectations');
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function crashedWorkersAreContainedAndTheRunCompletes(): void
    {
        [$exit, $output] = $this->runIn('CrashConfig', ['run', '--workers=2']);

        Expect::that($exit)->toBe(1)
            ->and($this->summaryLine($output))->toBe('3 tests, 2 passed, 1 errored, 0 expectations')
            ->and($output)->toContain('crashed while running');
    }

    #[Test]
    public function configuredPluginsReachWorkersAcrossTheProcessBoundary(): void
    {
        [$exit, $output] = $this->runIn('PluginRunConfig', ['run', '--workers=2']);

        Expect::that($exit)->toBe(0)
            ->and($this->summaryLine($output))->toBe('2 tests, 1 passed, 1 skipped, 0 expectations');
    }

    #[Test]
    public function workerRecyclingKeepsResultsIntact(): void
    {
        [$exit, $output] = $this->runIn('RecycleConfig', ['run']);

        Expect::that($exit)->toBe(0)
            ->and($this->summaryLine($output))->toBe('7 tests, 7 passed, 0 expectations');
    }

    #[Test]
    public function leakDetectionNamesTheLeakAndFailsTheRun(): void
    {
        [$withFlagExit, $withFlag] = $this->runIn('LeakConfig', ['run', '--detect-leaks', '--workers=2']);

        Expect::that($withFlagExit)->toBe(1)
            ->and($withFlag)->toContain('Leaks (the test instance survived its test):')
            ->and($withFlag)->toContain('  Greenlight\Tests\Fixture\LeakSuite\LeakyTest::passesButLeaksItself');

        [$withoutFlagExit] = $this->runIn('LeakConfig', ['run', '--workers=2']);

        Expect::that($withoutFlagExit)->toBe(0);
    }

    #[Test]
    public function leakDetectionWarnsWhenXdebugDevelopModeIsActive(): void
    {
        if (!\extension_loaded('xdebug')) {
            // The warning triggers on xdebug develop mode, an environment
            // property the test cannot create without the extension.
            throw new SkipTest('xdebug is not loaded');
        }

        [, $develop] = $this->runIn('LeakConfig', ['run', '--detect-leaks', '--workers=2'], ['XDEBUG_MODE' => 'develop']);

        Expect::that($develop)->toContain('xdebug develop mode');

        [, $off] = $this->runIn('LeakConfig', ['run', '--detect-leaks', '--workers=2'], ['XDEBUG_MODE' => 'off']);

        Expect::that($off)->not()->toContain('xdebug develop mode');
    }

    #[Test]
    public function hangingTestsAreHardKilledByTheOrchestrator(): void
    {
        $startedAt = \hrtime(true);
        [$exit, $output] = $this->runIn('HangConfig', ['run', '--workers=2']);
        $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;

        Expect::that($exit)->toBe(1)
            ->and($output)->toContain('timeout budget')
            ->and($durationSeconds)->toBeLessThan(20.0);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     *
     * @return array{int, string}
     */
    private function runIn(string $fixtureConfigDir, array $arguments, array $env = []): array
    {
        return AcceptanceProject::runIn(\dirname(__DIR__) . '/Fixture/' . $fixtureConfigDir, $arguments, $env);
    }

    private function summaryLine(string $output): string
    {
        if (\preg_match('/^\d+ tests?, \d+ passed(?:, \d+ failed)?(?:, \d+ errored)?(?:, \d+ skipped)?, \d+ expectations?$/m', $output, $matches) !== 1) {
            throw new \RuntimeException("No summary line found in output:\n" . $output);
        }

        return $matches[0];
    }
}
