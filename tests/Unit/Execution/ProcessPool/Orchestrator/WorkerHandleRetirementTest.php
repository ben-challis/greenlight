<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerHandle;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerLifecycle;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Tests\Support\ConnectedStreamPair;

final readonly class WorkerHandleRetirementTest
{
    #[Test]
    #[Timeout(5.0)]
    public function retirementReturnsBeforeTheWorkerExitsAndKeepsDiagnosticsSafe(): void
    {
        [$handle, $process, $pipes, $sockets] = $this->handle(
            'fwrite(STDOUT, "before\\n"); fflush(STDOUT); usleep(100000); '
            . 'fwrite(STDERR, "after\\n"); fflush(STDERR); usleep(1000000);',
        );

        try {
            \stream_set_timeout($pipes[1], 2);
            Expect::that(\fgets($pipes[1]))
                ->because('the process fixture MUST start before retirement')
                ->toBe("before\n");
            $before = \hrtime(true) / 1_000_000_000;
            $handle->retire($before, 0.3);
            $after = \hrtime(true) / 1_000_000_000;

            Expect::that($after - $before)
                ->because('retirement MUST return without waiting for worker exit')
                ->toBeLessThan(0.1);
            Expect::that($handle->lifecycle)
                ->toBe(WorkerLifecycle::Retiring);
            Expect::that($handle->channel?->isEof())
                ->because('retirement MUST close the protocol channel immediately')
                ->toBeTrue();

            $deadline = \microtime(true) + 2.0;

            while (!$handle->reap(\hrtime(true) / 1_000_000_000) && \microtime(true) < $deadline) {
                \usleep(10_000);
            }

            Expect::that($handle->lifecycle)
                ->because('the reaper MUST kill and collect a worker after its graceful deadline')
                ->toBe(WorkerLifecycle::Reaped);
            Expect::that($handle->diagnostics)
                ->because('retirement MUST continue to drain standard output and standard error')
                ->toBe("after\n");
            Expect::that(\is_resource($process))
                ->because('a reaped worker MUST not retain its process handle')
                ->toBeFalse();
        } finally {
            $this->cleanup($process, [...$pipes, ...$sockets]);
        }
    }

    /**
     * @return array{WorkerHandle, resource, array<int, resource>, array{resource, resource}}
     */
    private function handle(string $script): array
    {
        $process = \proc_open(
            [\PHP_BINARY, '-r', $script],
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

        \fclose($pipes[0]);
        unset($pipes[0]);
        $sockets = ConnectedStreamPair::open();
        $handle = new WorkerHandle('worker-1', 1, $process, $pipes[1], $pipes[2]);
        $handle->channel = new SocketChannel($sockets[0]);

        return [$handle, $process, $pipes, $sockets];
    }

    /**
     * @param resource $process
     * @param list<resource> $resources
     */
    private function cleanup(mixed $process, array $resources): void
    {
        foreach ($resources as $resource) {
            if (\is_resource($resource)) {
                ErrorTrap::run(static fn() => \fclose($resource));
            }
        }

        if (\is_resource($process)) {
            ErrorTrap::run(static fn() => \proc_terminate($process, 9));
            ErrorTrap::run(static fn() => \proc_close($process));
        }
    }
}
