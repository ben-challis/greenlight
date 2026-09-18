<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Execution\ProcessPool\Orchestrator\ResourceLease;
use Greenlight\Execution\ProcessPool\Orchestrator\SchedulingUnit;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerState;
use Greenlight\Result\ResultSummary;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\PlanEntryFixture;

use function Greenlight\expect;

final readonly class WorkerHandleAssignmentLifecycleTest
{
    #[Test]
    public function assignmentLifecycleTransfersResetsAndClearsWorkerState(): void
    {
        $handle = new WorkerState('worker-1', 1, 1.0);

        expect($handle->isFresh())
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

        expect($handle->lease)
            ->because('assignment start MUST transfer the resource lease')
            ->toBe($lease);
        expect($handle->assigned)
            ->because('assignment start MUST transfer the execution plan')
            ->toBe($lease->unit->plan);
        expect($handle->isolatedAssignment)
            ->because('assignment start MUST transfer the isolation state')
            ->toBeTrue();
        expect($handle->tally)
            ->because('assignment start MUST replace the result tally')
            ->not()->toBe($staleTally);
        expect($handle->tally->toWire())
            ->because('assignment start MUST reset each result count')
            ->toBe([
                'passed' => 0,
                'failed' => 0,
                'errored' => 0,
                'skipped' => 0,
            ]);
        expect($handle->finished)
            ->because('assignment start MUST clear the finished tests')
            ->toBeEmpty();
        expect($handle->inFlight)
            ->because('assignment start MUST clear the active test ID')
            ->toBeNull();
        expect($handle->inFlightAttempt)
            ->because('assignment start MUST reset the active attempt')
            ->toBe(0);
        expect($handle->isFresh())
            ->because('a worker MUST stay fresh until its first assignment completes')
            ->toBeTrue();

        $handle->inFlight = $lease->unit->plan->entries[0]->id;
        $handle->inFlightAttempt = 3;
        $handle->finishAssignment();

        expect($handle->lease)
            ->because('assignment finish MUST clear the resource lease')
            ->toBeNull();
        expect($handle->assigned)
            ->because('assignment finish MUST clear the execution plan')
            ->toBeNull();
        expect($handle->isolatedAssignment)
            ->because('assignment finish MUST clear the isolation state')
            ->toBeFalse();
        expect($handle->inFlight)
            ->because('assignment finish MUST clear the active test ID')
            ->toBeNull();
        expect($handle->inFlightAttempt)
            ->because('assignment finish MUST reset the active attempt')
            ->toBe(0);
        expect($handle->isFresh())
            ->because('assignment finish MUST mark the worker as used')
            ->toBeFalse();
    }

    private function lease(): ResourceLease
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('Acme\\ExampleTest'),
        ]);

        return new ResourceLease(41, new SchedulingUnit($plan, isolated: true));
    }
}
