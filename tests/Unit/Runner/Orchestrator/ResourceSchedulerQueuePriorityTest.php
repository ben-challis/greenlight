<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\DispatchKind;
use Greenlight\Runner\Orchestrator\ResourceScheduler;
use Greenlight\Tests\Support\SchedulingFixture;

final readonly class ResourceSchedulerQueuePriorityTest
{
    #[Test]
    public function pooledWorkPrecedesIsolatedWork(): void
    {
        $pooled = SchedulingFixture::unit('Acme\\PooledTest', isolated: false);
        $isolated = SchedulingFixture::unit('Acme\\IsolatedTest', isolated: true);
        $scheduler = new ResourceScheduler([$pooled], [$isolated], []);

        $pooledLease = SchedulingFixture::assignedLease($scheduler, freshWorker: true);
        $scheduler->release($pooledLease);
        $staleWorkerDecision = $scheduler->dispatch(false);
        $isolatedLease = SchedulingFixture::assignedLease($scheduler, freshWorker: true);

        Expect::that($pooledLease->unit->plan->classes())
            ->because('pooled work MUST run before isolated work')
            ->toBe(['Acme\\PooledTest']);
        Expect::that($staleWorkerDecision->kind)
            ->because('isolated work MUST wait for a fresh worker')
            ->toBe(DispatchKind::Drain);
        Expect::that($isolatedLease->unit->plan->classes())
            ->because('a fresh worker MUST receive the isolated work')
            ->toBe(['Acme\\IsolatedTest']);
    }

}
