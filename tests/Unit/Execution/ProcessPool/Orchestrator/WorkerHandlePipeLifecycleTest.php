<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerHandle;
use Greenlight\Expect\Expect;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\MemoryStream;

final readonly class WorkerHandlePipeLifecycleTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function pipeDrainSkipsClosedPipesWithoutDuplicatingDiagnostics(): void
    {
        $process = MemoryStream::open();
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open("standard error\n");
        $this->cleanup->defer(static fn() => MemoryStream::close($process, $stdout, $stderr));
        \fclose($stdout);

        $handle = new WorkerHandle('worker-1', 1, $process, $stdout, $stderr);

        $handle->drainPipes();
        $handle->drainPipes();

        Expect::that($handle->diagnostics)
            ->because('repeated pipe draining MUST retain each diagnostic byte once')
            ->toBe("standard error\n");

        \fclose($stderr);
        $handle->drainPipes();

        Expect::that($handle->diagnostics)
            ->because('pipe draining MUST skip closed output pipes')
            ->toBe("standard error\n");
    }
}
