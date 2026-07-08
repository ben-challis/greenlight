<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * Drives bin/greenlight run --watch as an interactive subprocess: the initial
 * run completes, a synthetic file touch triggers a debounced re-run, and q
 * quits cleanly.
 */
final class WatchModeTest
{
    #[Test]
    public function reRunsOnFileChangesAndQuitsOnQ(): void
    {
        $root = \dirname(__DIR__, 2);
        $cwd = $root . '/tests/Fixture/WatchConfig';
        $watchedFile = $root . '/tests/Fixture/WatchSuite/WatchDemoTest.php';
        $original = \file_get_contents($watchedFile);

        if ($original === false) {
            throw new \RuntimeException('Could not read the watched fixture.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = \proc_open(
            [\PHP_BINARY, $root . '/bin/greenlight', 'run', '--watch', '--reporter=plain'],
            $descriptors,
            $pipes,
            $cwd,
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the watch subprocess.');
        }

        \stream_set_blocking($pipes[1], false);

        try {
            $output = $this->readUntil($pipes[1], 'Watching for changes', 20.0);
            Expect::that($output)->toContain('1 test, 1 passed');

            // A synthetic change: append a comment, size changes, mtime may not.
            \file_put_contents($watchedFile, $original . "// touched\n");

            $output = $this->readUntil($pipes[1], 'Watching for changes', 20.0);
            Expect::that($output)->toContain('Change detected')
                ->and($output)->toContain('1 test, 1 passed');

            \fwrite($pipes[0], 'q');
            \fflush($pipes[0]);

            $deadline = \microtime(true) + 10.0;
            $running = true;

            while (\microtime(true) < $deadline) {
                $status = \proc_get_status($process);

                if (!$status['running']) {
                    $running = false;
                    Expect::that($status['exitcode'])->toBe(0);

                    break;
                }

                \usleep(50_000);
            }

            Expect::that($running)->toBeFalse();
        } finally {
            \file_put_contents($watchedFile, $original);
            @\fclose($pipes[0]);
            @\fclose($pipes[1]);
            @\fclose($pipes[2]);

            if (\proc_get_status($process)['running']) {
                \proc_terminate($process, 9);
            }

            \proc_close($process);
        }
    }

    /**
     * @param resource $stream
     */
    private function readUntil($stream, string $needle, float $timeoutSeconds): string
    {
        $deadline = \microtime(true) + $timeoutSeconds;
        $buffer = '';

        while (\microtime(true) < $deadline) {
            $bytes = \fread($stream, 8192);

            if (\is_string($bytes) && $bytes !== '') {
                $buffer .= $bytes;

                if (\str_contains($buffer, $needle)) {
                    return $buffer;
                }
            }

            \usleep(50_000);
        }

        throw new \RuntimeException(\sprintf(
            "Timed out waiting for '%s'. Output so far:\n%s",
            $needle,
            $buffer,
        ));
    }
}
