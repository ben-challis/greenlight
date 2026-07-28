<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Orchestrator\ResourceLease;
use Greenlight\Runner\Orchestrator\SchedulingUnit;
use Greenlight\Runner\Orchestrator\WorkerHandle;

final readonly class WorkerHandleAssignmentStateTest
{
    #[Test]
    public function assignmentLifecycleResetsStateAndConsumesFreshness(): void
    {
        $handle = $this->handle();
        $staleId = new TestId('Acme\\StaleTest', 'runs');
        $handle->tally = new ResultSummary(passed: 1);
        $handle->finished[(string) $staleId] = true;
        $handle->inFlight = $staleId;
        $handle->inFlightAttempt = 3;

        $plan = $this->plan();
        $lease = new ResourceLease(7, new SchedulingUnit($plan, isolated: true));
        $freshBeforeAssignment = $handle->isFresh();

        $handle->beginAssignment($lease);

        Expect::that([
            'freshBeforeAssignment' => $freshBeforeAssignment,
            'lease' => $handle->lease,
            'plan' => $handle->assigned,
            'isolated' => $handle->isolatedAssignment,
            'tally' => $handle->tally->total(),
            'finished' => $handle->finished,
            'inFlight' => $handle->inFlight,
            'attempt' => $handle->inFlightAttempt,
            'freshDuringFirstAssignment' => $handle->isFresh(),
        ])
            ->because('beginning an assignment MUST install it and reset previous execution state')
            ->toBe([
                'freshBeforeAssignment' => true,
                'lease' => $lease,
                'plan' => $plan,
                'isolated' => true,
                'tally' => 0,
                'finished' => [],
                'inFlight' => null,
                'attempt' => 0,
                'freshDuringFirstAssignment' => true,
            ]);

        $handle->inFlight = $plan->entries[0]->id;
        $handle->inFlightAttempt = 2;
        $handle->finishAssignment();

        Expect::that([
            'lease' => $handle->lease,
            'plan' => $handle->assigned,
            'isolated' => $handle->isolatedAssignment,
            'inFlight' => $handle->inFlight,
            'attempt' => $handle->inFlightAttempt,
            'freshAfterAssignment' => $handle->isFresh(),
        ])
            ->because('finishing an assignment MUST clear active state and consume worker freshness')
            ->toBe([
                'lease' => null,
                'plan' => null,
                'isolated' => false,
                'inFlight' => null,
                'attempt' => 0,
                'freshAfterAssignment' => false,
            ]);
    }

    private function plan(): ExecutionPlan
    {
        $id = new TestId('Acme\\ExampleTest', 'runs');

        return new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method)),
        ]);
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
