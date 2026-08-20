<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\Cleanup;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\ResourceLease;
use Greenlight\Runner\Orchestrator\SchedulingUnit;
use Greenlight\Runner\Orchestrator\WorkerHandle;
use Greenlight\Tests\Support\MemoryStream;

final readonly class WorkerHandleAssignmentLifecycleTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function assignmentLifecycleTransfersResetsAndClearsWorkerState(): void
    {
        $process = MemoryStream::open();
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($process, $stdout, $stderr));

        $handle = new WorkerHandle('worker-1', 1, $process, $stdout, $stderr);

        Expect::that($handle->isFresh())
            ->because('a worker MUST be fresh before its first assignment completes')
            ->toBeTrue();

        $staleTally = new ResultSummary(passed: 1);
        $staleInFlight = new TestId('Acme\\PreviousTest', 'runs');
        $handle->tally = $staleTally;
        $handle->finished = ['Acme\\PreviousTest::runs' => true];
        $handle->inFlight = $staleInFlight;
        $handle->inFlightAttempt = 2;

        $lease = $this->lease();
        $handle->beginAssignment($lease);

        Expect::that($handle->lease)
            ->because('assignment start MUST transfer the resource lease')
            ->toBe($lease);
        Expect::that($handle->assigned)
            ->because('assignment start MUST transfer the execution plan')
            ->toBe($lease->unit->plan);
        Expect::that($handle->isolatedAssignment)
            ->because('assignment start MUST transfer the isolation state')
            ->toBeTrue();
        Expect::that($handle->tally)
            ->because('assignment start MUST replace the result tally')
            ->not()->toBe($staleTally);
        Expect::that($handle->tally->toWire())
            ->because('assignment start MUST reset each result count')
            ->toBe([
                'passed' => 0,
                'failed' => 0,
                'errored' => 0,
                'skipped' => 0,
            ]);
        Expect::that($handle->finished)
            ->because('assignment start MUST clear the finished tests')
            ->toBeEmpty();
        Expect::that($handle->inFlight)
            ->because('assignment start MUST clear the active test ID')
            ->toBeNull();
        Expect::that($handle->inFlightAttempt)
            ->because('assignment start MUST reset the active attempt')
            ->toBe(0);
        Expect::that($handle->isFresh())
            ->because('a worker MUST stay fresh until its first assignment completes')
            ->toBeTrue();

        $handle->inFlight = $lease->unit->plan->entries[0]->id;
        $handle->inFlightAttempt = 3;
        $handle->finishAssignment();

        Expect::that($handle->lease)
            ->because('assignment finish MUST clear the resource lease')
            ->toBeNull();
        Expect::that($handle->assigned)
            ->because('assignment finish MUST clear the execution plan')
            ->toBeNull();
        Expect::that($handle->isolatedAssignment)
            ->because('assignment finish MUST clear the isolation state')
            ->toBeFalse();
        Expect::that($handle->inFlight)
            ->because('assignment finish MUST clear the active test ID')
            ->toBeNull();
        Expect::that($handle->inFlightAttempt)
            ->because('assignment finish MUST reset the active attempt')
            ->toBe(0);
        Expect::that($handle->isFresh())
            ->because('assignment finish MUST mark the worker as used')
            ->toBeFalse();
    }

    private function lease(): ResourceLease
    {
        $id = new TestId('Acme\\ExampleTest', 'runs');
        $plan = new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method)),
        ]);

        return new ResourceLease(41, new SchedulingUnit($plan, isolated: true));
    }

}
