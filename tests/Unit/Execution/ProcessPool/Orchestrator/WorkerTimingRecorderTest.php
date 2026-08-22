<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerIdleReason;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTimingRecorder;
use Greenlight\Expect\Expect;

final readonly class WorkerTimingRecorderTest
{
    #[Test]
    public function protocolTransitionsProduceLifecyclePhasesAndIdleAttribution(): void
    {
        $recorder = new WorkerTimingRecorder(10.0);
        $recorder->hello(10.1);
        $recorder->ready(10.4);
        $recorder->wait(WorkerIdleReason::BootstrapBarrier, 10.4);
        $recorder->wait(WorkerIdleReason::ResourceCapacity, 10.6);
        $recorder->assigned(10.9);
        $recorder->assignmentFinished(11.4);
        $recorder->wait(WorkerIdleReason::ResourceCapacity, 11.5);
        $recorder->assigned(11.7);
        $recorder->assignmentFinished(12.0);
        $recorder->wait(WorkerIdleReason::NoQueuedWork, 12.05);
        $recorder->retirementRequested(12.1);
        $recorder->exitObserved(12.25);

        $timing = $recorder->snapshot('worker-1');

        Expect::that($timing->spawnToHelloSeconds)->toBeWithin(0.000_001, 0.1);
        Expect::that($timing->helloToReadySeconds)->toBeWithin(0.000_001, 0.3);
        Expect::that($timing->readyToFirstAssignmentSeconds)->toBeWithin(0.000_001, 0.5);
        Expect::that($timing->assignmentGaps)->toBe(1);
        Expect::that($timing->assignmentGapSeconds)->toBeWithin(0.000_001, 0.3);
        Expect::that($timing->bootstrapBarrierSeconds)->toBeWithin(0.000_001, 0.2);
        Expect::that($timing->resourceCapacitySeconds)->toBeWithin(0.000_001, 0.6);
        Expect::that($timing->noQueuedWorkSeconds)->toBeWithin(0.000_001, 0.1);
        Expect::that($timing->retirementToExitSeconds)->toBeWithin(0.000_001, 0.15);
    }
}
