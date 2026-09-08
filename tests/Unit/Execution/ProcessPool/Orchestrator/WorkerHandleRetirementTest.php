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
use Greenlight\Tests\Support\PhpSubprocess;

final readonly class WorkerHandleRetirementTest
{
    #[Test]
    #[Timeout(5.0)]
    public function retirementReturnsBeforeTheWorkerExitsAndKeepsDiagnosticsSafe(): void
    {
        [$handle, $process, $pipes, $sockets] = $this->handle(
            <<<'PHP'
            fwrite(STDOUT, "ready\n");
            fflush(STDOUT);
            fgets(STDIN);
            fwrite(STDERR, "after\n");
            fflush(STDERR);
            fwrite(STDOUT, "written\n");
            fflush(STDOUT);
            fgets(STDIN);
            PHP,
        );

        try {
            \stream_set_timeout($pipes[1], 2);
            Expect::that(\fgets($pipes[1]))
                ->because('the process fixture MUST start before retirement')
                ->toBe("ready\n");
            $handle->retire(100.0, 1.0);

            Expect::that($handle->isRunning())
                ->because('retirement MUST return while the worker still waits for input')
                ->toBeTrue();
            Expect::that($handle->lifecycle)
                ->toBe(WorkerLifecycle::Retiring);
            Expect::that($handle->channel?->isEof())
                ->because('retirement MUST close the protocol channel immediately')
                ->toBeTrue();

            Expect::that(\fwrite($pipes[0], "continue\n"))->toBe(9);
            \fflush($pipes[0]);
            \stream_set_blocking($pipes[1], true);
            Expect::that(\fgets($pipes[1]))
                ->because('the worker MUST confirm its diagnostic write before the reaper advances')
                ->toBe("written\n");

            Expect::that($handle->reap(100.999))->toBeFalse();
            Expect::that($handle->lifecycle)
                ->because('the reaper MUST preserve a worker before its graceful deadline')
                ->toBe(WorkerLifecycle::Retiring);
            Expect::that($handle->diagnostics)
                ->because('retirement MUST continue to drain diagnostics before the deadline')
                ->toBe("after\n");

            Expect::that($handle->reap(101.0))->toBeFalse();
            Expect::that($handle->lifecycle)
                ->because('the reaper MUST kill the worker at the exact graceful deadline')
                ->toBe(WorkerLifecycle::Killing);

            $deadline = \microtime(true) + 2.0;

            while (!$handle->reap(102.0) && \microtime(true) < $deadline) {
                $read = [$pipes[1]];
                $write = null;
                $except = null;
                \stream_select($read, $write, $except, 0, 100_000);
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
            PhpSubprocess::command(['-r', $script]),
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
