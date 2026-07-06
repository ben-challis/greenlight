<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * Drives bin/greenlight with a process pool against fixture projects and
 * asserts on observable behaviour: exit codes and summary lines. Crash and
 * hang fixtures must only ever run through here, never in-process.
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
            ->and($this->summaryLine($sequential))->toBe('7 tests: 7 passed, 0 failed, 0 errored, 0 skipped')
            ->and($this->summaryLine($parallel))->toBe('7 tests: 7 passed, 0 failed, 0 errored, 0 skipped');
    }

    #[Test]
    public function crashedWorkersAreContainedAndTheRunCompletes(): void
    {
        [$exit, $output] = $this->runIn('CrashConfig', ['run', '--workers=2']);

        new Expect()->that($exit)->toBe(1)
            ->and($this->summaryLine($output))->toBe('3 tests: 2 passed, 0 failed, 1 errored, 0 skipped')
            ->and($output)->toContain('crashed while running');
    }

    #[Test]
    public function workerRecyclingKeepsResultsIntact(): void
    {
        [$exit, $output] = $this->runIn('RecycleConfig', ['run']);

        new Expect()->that($exit)->toBe(0)
            ->and($this->summaryLine($output))->toBe('7 tests: 7 passed, 0 failed, 0 errored, 0 skipped');
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
        if (\preg_match('/^\d+ tests: .*(?= \()/m', $output, $matches) !== 1) {
            throw new \RuntimeException("No summary line found in output:\n" . $output);
        }

        return $matches[0];
    }
}
