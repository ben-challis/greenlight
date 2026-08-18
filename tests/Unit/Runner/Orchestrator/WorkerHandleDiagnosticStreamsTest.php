<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\WorkerHandle;
use Greenlight\Tests\Support\MemoryStream;

final readonly class WorkerHandleDiagnosticStreamsTest
{
    #[Test]
    public function pipeDrainKeepsStdoutBeforeStderr(): void
    {
        $handle = new WorkerHandle(
            'worker-1',
            1,
            MemoryStream::open(),
            MemoryStream::open("standard output\n"),
            MemoryStream::open("standard error\n"),
        );

        $handle->drainPipes();

        Expect::that($handle->diagnostics)
            ->because('worker diagnostics MUST contain standard output followed by standard error')
            ->toBe("standard output\nstandard error\n");
    }

}
