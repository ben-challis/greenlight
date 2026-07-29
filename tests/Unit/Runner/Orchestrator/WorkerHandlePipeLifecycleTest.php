<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Orchestrator\WorkerHandle;

final readonly class WorkerHandlePipeLifecycleTest
{
    #[Test]
    public function pipeDrainSkipsClosedPipesWithoutDuplicatingDiagnostics(): void
    {
        $process = $this->stream();
        $stdout = $this->stream();
        $stderr = $this->stream("standard error\n");
        \fclose($stdout);

        try {
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
        } finally {
            $this->close($process);
            $this->close($stdout);
            $this->close($stderr);
        }
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

    private function close(mixed $stream): void
    {
        if (\is_resource($stream)) {
            \fclose($stream);
        }
    }
}
