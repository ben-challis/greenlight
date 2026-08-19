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

    #[Test]
    public function pipeDrainKeepsTheBoundedTailOfAvailableOutput(): void
    {
        $tail = \str_repeat('t', 65_536);
        $handle = new WorkerHandle(
            'worker-1',
            1,
            MemoryStream::open(),
            MemoryStream::open(\str_repeat('p', 8_192) . $tail),
            MemoryStream::open(),
        );

        $handle->drainPipes();

        Expect::that($handle->diagnostics)
            ->because('one pipe drain MUST retain the bounded tail of all available output')
            ->toBe($tail);
    }

}
