<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Execution\ProcessPool\Orchestrator\ResourceLease;
use Greenlight\Execution\ProcessPool\Orchestrator\SchedulingUnit;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerPhase;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerState;
use Greenlight\Tests\Support\PlanEntryFixture;

use function Greenlight\expect;

final readonly class WorkerStatePhaseTest
{
    #[Test]
    public function aWorkerMovesThroughItsLifecycle(): void
    {
        $handle = new WorkerState('w-1', 1, 1.0);

        expect($handle->phase())->toBe(WorkerPhase::Spawned);
        expect($handle->canTakeAssignment())
            ->because('a starting worker counts as capacity for queued work')
            ->toBeTrue();

        $handle->connect(2.0);

        expect($handle->phase())->toBe(WorkerPhase::Bootstrapping);
        expect($handle->isConnected())->toBeTrue();
        expect($handle->lastProgressAt)->toBe(2.0);

        $handle->markReady();

        expect($handle->phase())->toBe(WorkerPhase::Idle);
        expect($handle->isIdle())->toBeTrue();

        $handle->beginAssignment($this->lease());

        expect($handle->phase())->toBe(WorkerPhase::Assigned);
        expect($handle->canTakeAssignment())->toBeFalse();

        $handle->finishAssignment();

        expect($handle->phase())->toBe(WorkerPhase::Idle);

        $handle->requestStop(3.0);

        expect($handle->phase())->toBe(WorkerPhase::Draining);
        expect($handle->isStopRequested())->toBeTrue();
        expect($handle->canTakeAssignment())
            ->because('a worker that received Drain cannot take work')
            ->toBeFalse();

        $handle->retire();

        expect($handle->phase())->toBe(WorkerPhase::Retiring);
        expect($handle->isActive())->toBeFalse();
    }

    #[Test]
    public function aDrainBeforeReadyKeepsTheWorkerDraining(): void
    {
        $handle = new WorkerState('w-1', 1, 1.0);
        $handle->connect(2.0);
        $handle->requestStop(3.0);
        $handle->markReady();

        expect($handle->phase())
            ->because('Ready after Drain MUST NOT make the worker idle')
            ->toBe(WorkerPhase::Draining);
        expect($handle->isReady())->toBeTrue();
    }

    #[Test]
    public function aDrainDuringAnAssignmentOutlastsTheAssignment(): void
    {
        $handle = new WorkerState('w-1', 1, 1.0);
        $handle->connect(2.0);
        $handle->markReady();
        $handle->beginAssignment($this->lease());
        $handle->requestStop(3.0);
        $handle->finishAssignment();

        expect($handle->phase())
            ->because('the end of the assignment MUST NOT cancel the drain')
            ->toBe(WorkerPhase::Draining);
        expect($handle->assigned)->toBeNull();
    }

    private function lease(): ResourceLease
    {
        return new ResourceLease(1, new SchedulingUnit(
            new ExecutionPlan([PlanEntryFixture::create('Acme\\ExampleTest')]),
            isolated: false,
        ));
    }
}
