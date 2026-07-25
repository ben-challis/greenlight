<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

/** Crash and hang fixtures must never run in-process. */
final readonly class ParallelRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function parallelResultsMatchSequentialResults(): void
    {
        // A private copy of ListTestsConfig, so this comparison run cannot
        // race another acceptance test's use of the same working directory.
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'parallel');
        $sequential = GreenlightCli::run($project->directory, ['run', '--workers=1']);
        $parallel = GreenlightCli::run($project->directory, ['run', '--workers=3']);
        Expect::that($sequential->exitCode)->toBe(0)
            ->and($parallel->exitCode)->toBe(0)
            ->and($this->summaryLine($sequential->output()))->toBe('7 tests, 7 passed, 0 expectations')
            ->and($this->summaryLine($parallel->output()))->toBe('7 tests, 7 passed, 0 expectations');
    }

    #[Test]
    public function crashedWorkersAreContainedAndTheRunCompletes(): void
    {
        $result = $this->runIn('CrashConfig', ['run', '--workers=2']);

        Expect::that($result->exitCode)->toBe(1)
            ->and($this->summaryLine($result->output()))->toBe('3 tests, 2 passed, 1 errored, 0 expectations')
            ->and($result->output())->toContain('crashed while running');
    }

    #[Test]
    public function configuredPluginsReachWorkersAcrossTheProcessBoundary(): void
    {
        $result = $this->runIn('PluginRunConfig', ['run', '--workers=2']);

        Expect::that($result->exitCode)->toBe(0)
            ->and($this->summaryLine($result->output()))->toBe('2 tests, 1 passed, 1 skipped, 0 expectations');
    }

    #[Test]
    public function workerRecyclingKeepsResultsIntact(): void
    {
        $result = $this->runIn('RecycleConfig', ['run']);

        Expect::that($result->exitCode)->toBe(0)
            ->and($this->summaryLine($result->output()))->toBe('7 tests, 7 passed, 0 expectations');
    }

    #[Test]
    public function leakDetectionNamesTheLeakAndFailsTheRun(): void
    {
        $withFlag = $this->runIn('LeakConfig', ['run', '--detect-leaks', '--workers=2']);

        Expect::that($withFlag->exitCode)->toBe(1)
            ->and($withFlag->output())->toContain('Leaks (the test instance survived its test):')
            ->toContain('  Greenlight\Tests\Fixture\LeakSuite\LeakyTest::passesButLeaksItself');

        $withoutFlag = $this->runIn('LeakConfig', ['run', '--workers=2']);

        Expect::that($withoutFlag->exitCode)->toBe(0);
    }

    #[Test]
    public function leakDetectionWarnsWhenXdebugDevelopModeIsActive(): void
    {
        if (!\extension_loaded('xdebug')) {
            // The warning triggers on xdebug develop mode, an environment
            // property the test cannot create without the extension.
            throw new SkipTest('xdebug is not loaded');
        }

        $develop = $this->runIn('LeakConfig', ['run', '--detect-leaks', '--workers=2'], ['XDEBUG_MODE' => 'develop']);

        Expect::that($develop->output())->toContain('xdebug develop mode');

        $off = $this->runIn('LeakConfig', ['run', '--detect-leaks', '--workers=2'], ['XDEBUG_MODE' => 'off']);

        Expect::that($off->output())->not()->toContain('xdebug develop mode');
    }

    #[Test]
    public function hangingTestsAreHardKilledByTheOrchestrator(): void
    {
        $startedAt = \hrtime(true);
        $result = $this->runIn('HangConfig', ['run', '--workers=2']);
        $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('timeout budget')
            ->and($durationSeconds)->toBeLessThan(20.0);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     */
    private function runIn(string $fixtureConfigDir, array $arguments, array $env = []): ProcessResult
    {
        return GreenlightCli::run(\dirname(__DIR__) . '/Fixture/' . $fixtureConfigDir, $arguments, $env);
    }

    private function summaryLine(string $output): string
    {
        if (\preg_match('/^\d+ tests?, \d+ passed(?:, \d+ failed)?(?:, \d+ errored)?(?:, \d+ skipped)?, \d+ expectations?$/m', $output, $matches) !== 1) {
            Fail::because("No summary line found in output:\n" . $output);
        }

        return $matches[0];
    }
}
