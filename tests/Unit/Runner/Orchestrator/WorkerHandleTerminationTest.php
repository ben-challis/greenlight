<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Core\ErrorTrap;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Orchestrator\WorkerHandle;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Tests\Support\ConnectedStreamPair;

final readonly class WorkerHandleTerminationTest
{
    #[Test]
    #[Timeout(5.0)]
    public function terminationClosesTheChannelAndProcess(): void
    {
        $process = null;
        $pipes = [];
        $sockets = [];

        try {
            $process = \proc_open(
                [
                    \PHP_BINARY,
                    '-r',
                    'fwrite(STDOUT, "ready\n"); fflush(STDOUT); while (true) { usleep(10000); }',
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

            $sockets = ConnectedStreamPair::open();

            \stream_set_timeout($pipes[1], 2);

            Expect::that(\fgets($pipes[1]))
                ->because('the worker process fixture MUST signal that it is ready')
                ->toBe("ready\n");

            $channel = new SocketChannel($sockets[0]);
            $handle = new WorkerHandle(
                'worker-1',
                1,
                $process,
                $pipes[1],
                $pipes[2],
            );
            $handle->channel = $channel;
            $handle->terminate();

            Expect::that($channel->isEof())
                ->because('worker termination MUST close its protocol channel')
                ->toBeTrue();
            Expect::that(\is_resource($process))
                ->because('worker termination MUST close its process handle')
                ->toBeFalse();
        } finally {
            $resources = $pipes;

            $resources = [...$resources, ...$sockets];

            foreach ($resources as $resource) {
                if (\is_resource($resource)) {
                    ErrorTrap::run(static fn(): bool => \fclose($resource));
                }
            }

            if (\is_resource($process)) {
                ErrorTrap::run(static fn(): bool => \proc_terminate($process, 9));
                ErrorTrap::run(static fn(): int => \proc_close($process));
            }
        }
    }
}
