<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Orchestrator\WorkerHandle;

final class WorkerHandleZeroDiagnosticsTest
{
    #[Test]
    public function pipeDrainRetainsZeroDiagnostics(): void
    {
        $handle = new WorkerHandle(
            'worker-1',
            1,
            $this->stream(),
            $this->stream('0'),
            $this->stream(),
        );

        $handle->drainPipes();

        Expect::that($handle->diagnostics)
            ->because('pipe draining MUST retain non-empty diagnostics')
            ->toBe('0');
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
