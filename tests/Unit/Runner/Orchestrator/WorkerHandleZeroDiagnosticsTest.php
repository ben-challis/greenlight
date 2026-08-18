<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\WorkerHandle;
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
