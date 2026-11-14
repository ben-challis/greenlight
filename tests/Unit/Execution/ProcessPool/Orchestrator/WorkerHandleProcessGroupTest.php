<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Condition\FunctionAvailable;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerHandle;
use Greenlight\Expect\Fail;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\PhpSubprocess;

use function Greenlight\expect;

final readonly class WorkerHandleProcessGroupTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    #[DataSet('stops')]
    #[SkipUnless(FunctionAvailable::class, 'posix_setpgid')]
    #[Timeout(10.0)]
    public function aKilledWorkerTakesItsChildProcessesWithIt(string $stop): void
    {
        // The worker leads a process group, like a worker in a real run, and
        // starts a child that runs longer than the test.
        $process = \proc_open(
            PhpSubprocess::command([
                '-r',
                <<<'PHP_WRAP'
                posix_setpgid(0, 0);
                $child = proc_open([PHP_BINARY, '-r', 'sleep(30);'], [], $pipes);
                fwrite(STDOUT, proc_get_status($child)['pid'] . "\n");
                fflush(STDOUT);
                sleep(30);
                PHP_WRAP,
            ]),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!\is_resource($process)) {
            Fail::because('Expected the worker process fixture to start.');
        }

        \stream_set_timeout($pipes[1], 5);
        $childPid = (int) \fgets($pipes[1]);
        $this->cleanup->defer(static function () use ($childPid): void {
            if ($childPid > 1) {
                ErrorTrap::run(static fn() => \posix_kill($childPid, 9));
            }
        });

        expect($childPid)
            ->because('the worker fixture MUST report its child process ID')
            ->toBeGreaterThan(1);

        $handle = new WorkerHandle('w-1', 1, $process, $pipes[1], $pipes[2]);

        if ($stop === 'kill') {
            $handle->kill(0.0);
            $deadline = \hrtime(true) + 5_000_000_000;

            while (!$handle->reap(0.0) && \hrtime(true) < $deadline) {
                \usleep(10_000);
            }
        } else {
            $handle->terminate();
        }

        $deadline = \hrtime(true) + 5_000_000_000;

        while (ErrorTrap::run(static fn(): bool => \posix_kill($childPid, 0)) && \hrtime(true) < $deadline) {
            \usleep(10_000);
        }

        expect(ErrorTrap::run(static fn(): bool => \posix_kill($childPid, 0)))
            ->because('a hard stop MUST also stop the processes that the worker started')
            ->toBeFalse();
    }

    /** @return iterable<string, array{string}> */
    public static function stops(): iterable
    {
        yield 'timeout kill' => ['kill'];
        yield 'transport close' => ['terminate'];
    }
}
