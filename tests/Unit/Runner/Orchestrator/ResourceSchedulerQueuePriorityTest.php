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
use Greenlight\Runner\Orchestrator\DispatchKind;
use Greenlight\Runner\Orchestrator\ResourceLease;
use Greenlight\Runner\Orchestrator\ResourceScheduler;
use Greenlight\Runner\Orchestrator\SchedulingUnit;

final readonly class ResourceSchedulerQueuePriorityTest
{
    #[Test]
    public function pooledWorkPrecedesIsolatedWork(): void
    {
        $pooled = $this->unit('Acme\\PooledTest', isolated: false);
        $isolated = $this->unit('Acme\\IsolatedTest', isolated: true);
        $scheduler = new ResourceScheduler([$pooled], [$isolated], []);

        $pooledLease = $this->assigned($scheduler, freshWorker: true);
        $scheduler->release($pooledLease);
        $staleWorkerDecision = $scheduler->dispatch(false);
        $isolatedLease = $this->assigned($scheduler, freshWorker: true);

        Expect::that([
            'first' => $pooledLease->unit->plan->classes(),
            'staleWorker' => $staleWorkerDecision->kind,
            'second' => $isolatedLease->unit->plan->classes(),
        ])
            ->because('pooled work MUST run first and isolated work MUST wait for a fresh worker')
            ->toBe([
                'first' => ['Acme\\PooledTest'],
                'staleWorker' => DispatchKind::Drain,
                'second' => ['Acme\\IsolatedTest'],
            ]);
    }

    /**
     * @param non-empty-string $class
     */
    private function unit(string $class, bool $isolated): SchedulingUnit
    {
        $id = new TestId($class, 'runs');

        return new SchedulingUnit(new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($class, 'runs')),
        ]), $isolated);
    }

    private function assigned(ResourceScheduler $scheduler, bool $freshWorker): ResourceLease
    {
        $decision = $scheduler->dispatch($freshWorker);

        if (!$decision->lease instanceof ResourceLease) {
            Fail::because(\sprintf('Expected an assignment, got %s.', $decision->kind->name));
        }

        return $decision->lease;
    }
}
