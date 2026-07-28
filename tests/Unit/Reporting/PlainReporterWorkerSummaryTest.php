<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\PlainReporter;

final class PlainReporterWorkerSummaryTest
{
    #[Test]
    public function oneSpawnedWorkerIsReportedWithoutAZeroRecycleCount(): void
    {
        $output = new BufferOutput();
        $reporter = new PlainReporter($output);
        $reporter->onEvent(new WorkerSpawned('w-1', 101, 1.0));
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1), 0.1, 1.3));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('the plain summary MUST report its only spawned worker')
            ->toContain("Workers: 1 spawned\n")
            ->not()
            ->toContain('recycled');
    }

    #[Test]
    public function inProcessRunsDoNotRenderAWorkerSummary(): void
    {
        $output = new BufferOutput();
        $reporter = new PlainReporter($output);
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1), 0.1, 1.3));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('an in-process run MUST NOT report a spawned worker')
            ->not()
            ->toContain('Workers:');
    }

    #[Test]
    public function recycleReasonsAreCountedInCanonicalOrder(): void
    {
        $output = new BufferOutput();
        $reporter = new PlainReporter($output);
        $reporter->onEvent(new WorkerSpawned('w-1', 101, 1.0));
        $reporter->onEvent(new WorkerRecycled('w-1', RecycleReason::Crash, 1.1));
        $reporter->onEvent(new WorkerRecycled('w-2', RecycleReason::Memory, 1.2));
        $reporter->onEvent(new WorkerRecycled('w-3', RecycleReason::TestCount, 1.3));
        $reporter->onEvent(new WorkerRecycled('w-4', RecycleReason::Crash, 1.4));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('the plain summary MUST count and order every worker recycle reason')
            ->toBe("Workers: 1 spawned, 4 recycled (test-count: 1, memory: 1, crash: 2)\n");
    }
}
