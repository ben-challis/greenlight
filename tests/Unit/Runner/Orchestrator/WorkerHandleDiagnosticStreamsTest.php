<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Orchestrator\WorkerHandle;

final readonly class WorkerHandleDiagnosticStreamsTest
{
    #[Test]
    public function pipeDrainKeepsStdoutBeforeStderr(): void
    {
        $handle = new WorkerHandle(
            'worker-1',
            1,
            $this->stream(),
            $this->stream("standard output\n"),
            $this->stream("standard error\n"),
        );

        $handle->drainPipes();

        Expect::that($handle->diagnostics)
            ->because('worker diagnostics MUST contain standard output followed by standard error')
            ->toBe("standard output\nstandard error\n");
    }

    /**
     * @return resource
     */
    private function stream(string $contents = ''): mixed
    {
        $stream = \fopen('php://memory', 'r+');

        if (!\is_resource($stream)) {
            Fail::because('Expected the in-memory stream to open.');
        }

        \fwrite($stream, $contents);
        \rewind($stream);

        return $stream;
    }
}
