<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerHandle;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\MemoryStream;

final class WorkerHandleZeroDiagnosticsTest
{
    #[Test]
    public function pipeDrainRetainsZeroDiagnostics(): void
    {
        $handle = new WorkerHandle(
            'worker-1',
            1,
            MemoryStream::open(),
            MemoryStream::open('0'),
            MemoryStream::open(),
        );

        $handle->drainPipes();

        Expect::that($handle->diagnostics)
            ->because('pipe draining MUST retain non-empty diagnostics')
            ->toBe('0');
    }

}
