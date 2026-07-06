<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;

/**
 * Runs the bootstrap runner as a subprocess against fixture suites and asserts
 * on observable behaviour only (exit codes, hook log), so a broken runner fails
 * loudly instead of reporting green.
 */
final class BootstrapRunnerTest
{
    #[Test]
    public function passingSuiteExitsZeroAndRunsHooksInOrder(): void
    {
        [$exit, $log] = $this->runFixtureSuite('tests/Fixture/BootstrapPassing');

        if ($exit !== 0) {
            throw new \RuntimeException(\sprintf('Expected exit 0 for the passing suite, got %d.', $exit));
        }

        if ($log !== "before\ntest\nafter\n") {
            throw new \RuntimeException(\sprintf('Unexpected hook order log: %s', \var_export($log, true)));
        }
    }

    #[Test]
    public function failingSuiteExitsOneAndStillRunsAfterHooks(): void
    {
        [$exit, $log] = $this->runFixtureSuite('tests/Fixture/BootstrapFailing');

        if ($exit !== 1) {
            throw new \RuntimeException(\sprintf('Expected exit 1 for the failing suite, got %d.', $exit));
        }

        if ($log !== "after\n") {
            throw new \RuntimeException('The #[After] hook must run even when the test fails.');
        }
    }

    #[Test]
    public function emptySuiteExitsNonZero(): void
    {
        [$exit] = $this->runFixtureSuite('tests/Fixture/BootstrapEmpty');

        if ($exit !== 1) {
            throw new \RuntimeException(\sprintf('A run that finds no tests must fail; got exit %d.', $exit));
        }
    }

    /**
     * @return array{int, string}
     */
    private function runFixtureSuite(string $relativeDir): array
    {
        $root = \dirname(__DIR__, 2);
        $logFile = \tempnam(\sys_get_temp_dir(), 'greenlight-fixture-');

        if ($logFile === false) {
            throw new \RuntimeException('Could not create a temporary log file.');
        }

        try {
            $command = \sprintf(
                'GREENLIGHT_FIXTURE_LOG=%s %s %s %s 2>&1',
                \escapeshellarg($logFile),
                \escapeshellarg(\PHP_BINARY),
                \escapeshellarg($root . '/tools/bootstrap-runner.php'),
                \escapeshellarg($root . '/' . $relativeDir),
            );

            \exec($command, $output, $exit);

            $log = \file_get_contents($logFile);

            if ($log === false) {
                throw new \RuntimeException('Could not read the fixture log.');
            }

            return [$exit, $log];
        } finally {
            @\unlink($logFile);
        }
    }
}
