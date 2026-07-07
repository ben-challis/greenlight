<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

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
        $expect = new Expect();

        [$sequentialExit, $sequential] = $this->runIn('ListTestsConfig', ['run', '--workers=1']);
        [$parallelExit, $parallel] = $this->runIn('ListTestsConfig', ['run', '--workers=3']);

        $expect->that($sequentialExit)->toBe(0)
            ->and($parallelExit)->toBe(0)
            ->and($this->summaryLine($sequential))->toBe('Tests: 7, Passed: 7, Failed: 0, Errored: 0, Skipped: 0, Expectations: 0')
            ->and($this->summaryLine($parallel))->toBe('Tests: 7, Passed: 7, Failed: 0, Errored: 0, Skipped: 0, Expectations: 0');
    }

    #[Test]
    public function crashedWorkersAreContainedAndTheRunCompletes(): void
    {
        [$exit, $output] = $this->runIn('CrashConfig', ['run', '--workers=2']);

        new Expect()->that($exit)->toBe(1)
            ->and($this->summaryLine($output))->toBe('Tests: 3, Passed: 2, Failed: 0, Errored: 1, Skipped: 0, Expectations: 0')
            ->and($output)->toContain('crashed while running');
    }

    #[Test]
    public function configuredPluginsReachWorkersAcrossTheProcessBoundary(): void
    {
        [$exit, $output] = $this->runIn('PluginRunConfig', ['run', '--workers=2']);

        new Expect()->that($exit)->toBe(0)
            ->and($this->summaryLine($output))->toBe('Tests: 2, Passed: 1, Failed: 0, Errored: 0, Skipped: 1, Expectations: 0');
    }

    #[Test]
    public function workerRecyclingKeepsResultsIntact(): void
    {
        [$exit, $output] = $this->runIn('RecycleConfig', ['run']);

        new Expect()->that($exit)->toBe(0)
            ->and($this->summaryLine($output))->toBe('Tests: 7, Passed: 7, Failed: 0, Errored: 0, Skipped: 0, Expectations: 0');
    }

    #[Test]
    public function leakDetectionNamesTheLeakAndFailsTheRun(): void
    {
        $expect = new Expect();

        [$withFlagExit, $withFlag] = $this->runIn('LeakConfig', ['run', '--detect-leaks', '--workers=2']);

        $expect->that($withFlagExit)->toBe(1)
            ->and($withFlag)->toContain('LEAK Greenlight\Tests\Fixture\LeakSuite\LeakyTest::passesButLeaksItself');

        [$withoutFlagExit] = $this->runIn('LeakConfig', ['run', '--workers=2']);

        $expect->that($withoutFlagExit)->toBe(0);
    }

    #[Test]
    public function hangingTestsAreHardKilledByTheOrchestrator(): void
    {
        $startedAt = \hrtime(true);
        [$exit, $output] = $this->runIn('HangConfig', ['run', '--workers=2']);
        $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;

        new Expect()->that($exit)->toBe(1)
            ->and($output)->toContain('timeout budget')
            ->and($durationSeconds)->toBeLessThan(20.0);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function runIn(string $fixtureConfigDir, array $arguments): array
    {
        $root = \dirname(__DIR__, 2);
        $parts = [\escapeshellarg(\PHP_BINARY), \escapeshellarg($root . '/bin/greenlight')];

        foreach ($arguments as $argument) {
            $parts[] = \escapeshellarg($argument);
        }

        $command = \sprintf(
            'cd %s && %s 2>&1',
            \escapeshellarg($root . '/tests/Fixture/' . $fixtureConfigDir),
            \implode(' ', $parts),
        );

        \exec($command, $output, $exit);

        return [$exit, \implode("\n", $output)];
    }

    private function summaryLine(string $output): string
    {
        if (\preg_match('/^Tests: \d+, Passed: \d+, Failed: \d+, Errored: \d+, Skipped: \d+, Expectations: \d+$/m', $output, $matches) !== 1) {
            throw new \RuntimeException("No summary line found in output:\n" . $output);
        }

        return $matches[0];
    }
}
