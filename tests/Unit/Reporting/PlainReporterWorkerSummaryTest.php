<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\PlainReporter;

final class PlainReporterWorkerSummaryTest
{
    #[Test]
    public function oneSpawnedWorkerIsReported(): void
    {
        $output = new BufferOutput();
        $reporter = new PlainReporter($output);
        $reporter->onEvent(new WorkerSpawned('w-1', 101, 1.0));
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1), 0.1, 1.3));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('the plain summary MUST report its only spawned worker')
            ->toContain("Workers: 1 spawned\n");
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

}
