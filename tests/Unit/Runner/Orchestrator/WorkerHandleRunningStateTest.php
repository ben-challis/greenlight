<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Core\ErrorTrap;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Orchestrator\WorkerHandle;

final readonly class WorkerHandleRunningStateTest
{
    #[Test]
    #[Timeout(5.0)]
    public function processStatusDistinguishesLiveAndExitedWorkers(): void
    {
        $process = null;
        $pipes = [];

        try {
            $process = \proc_open(
                [
                    \PHP_BINARY,
                    '-r',
                    'fwrite(STDOUT, "ready\n"); fflush(STDOUT); fgets(STDIN); '
                    . 'fwrite(STDOUT, "exiting\n"); fflush(STDOUT);',
                ],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                options: ['bypass_shell' => true],
            );

            if (!\is_resource($process)) {
                Fail::because('Expected the worker process fixture to start.');
            }

            \stream_set_timeout($pipes[1], 2);

            Expect::that(\fgets($pipes[1]))
                ->because('the worker process fixture MUST signal that it is ready')
                ->toBe("ready\n");

            $handle = new WorkerHandle(
                'worker-1',
                1,
                $process,
                $pipes[1],
                $pipes[2],
            );

            Expect::that($handle->isRunning())
                ->because('a readiness-confirmed worker process MUST report that it is running')
                ->toBeTrue();

            \fwrite($pipes[0], "exit\n");
            \fflush($pipes[0]);

            Expect::that(\fgets($pipes[1]))
                ->because('the worker process fixture MUST signal that it is exiting')
                ->toBe("exiting\n");

            \stream_get_contents($pipes[1]);
            $metadata = \stream_get_meta_data($pipes[1]);

            Expect::that([\feof($pipes[1]), $metadata['timed_out']])
                ->because('the worker process fixture MUST reach EOF before its stopped state is checked')
                ->toBe([true, false]);

            $deadline = \microtime(true) + 1.0;

            do {
                $running = $handle->isRunning();

                if ($running) {
                    \usleep(1_000);
                }
            } while ($running && \microtime(true) < $deadline);

            Expect::that([\is_resource($process), $running])
                ->because('an exited worker keeps a process handle but MUST not report that it is running')
                ->toBe([true, false]);
        } finally {
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    ErrorTrap::run(static fn(): bool => \fclose($pipe));
                }
            }

            if (\is_resource($process)) {
                ErrorTrap::run(static fn(): bool => \proc_terminate($process, 9));
                ErrorTrap::run(static fn(): int => \proc_close($process));
            }
        }
    }
}
