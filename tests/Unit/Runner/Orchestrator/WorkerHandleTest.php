<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Orchestrator\WorkerHandle;

final class WorkerHandleTest
{
    #[Test]
    public function unfinishedReturnsOnlyEntriesEligibleForCrashReassignment(): void
    {
        $handle = $this->handle();

        Expect::that($handle->unfinished())
            ->because('a worker without an assignment has no unfinished tests')
            ->toBe([]);

        $finished = $this->entry('finished');
        $inFlight = $this->entry('inFlight');
        $remaining = $this->entry('remaining');
        $handle->assigned = new ExecutionPlan([$finished, $inFlight, $remaining]);
        $handle->finished[(string) $finished->id] = true;
        $handle->inFlight = $inFlight->id;

        Expect::that($handle->unfinished())
            ->because('crash reassignment excludes finished and active tests')
            ->toBe([$remaining->id]);
    }

    /**
     * @param non-empty-string $method
     */
    private function entry(string $method): PlanEntry
    {
        $id = new TestId('Example\WorkerTest', $method);

        return new PlanEntry($id, new TestMetadata($id->class, $id->method));
    }

    private function handle(): WorkerHandle
    {
        return new WorkerHandle(
            'worker-1',
            1,
            $this->stream(),
            $this->stream(),
            $this->stream(),
        );
    }

    /**
     * @return resource
     */
    private function stream(): mixed
    {
        $stream = \fopen('php://memory', 'r+');

        if (!\is_resource($stream)) {
            Fail::because('Expected the in-memory stream to open.');
        }

        return $stream;
    }
}
