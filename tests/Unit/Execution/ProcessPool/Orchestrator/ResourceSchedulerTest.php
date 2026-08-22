<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\DispatchKind;
use Greenlight\Execution\ProcessPool\Orchestrator\ResourceScheduler;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\SchedulingFixture;

final class ResourceSchedulerTest
{
    #[Test]
    public function sharedResourceCapacityReducesTheInitialWorkerTarget(): void
    {
        $scheduler = new ResourceScheduler([
            SchedulingFixture::unit('FirstTest', ['database']),
            SchedulingFixture::unit('SecondTest', ['database']),
            SchedulingFixture::unit('ThirdTest', ['database']),
        ], [], ['database' => 2]);

        Expect::that($scheduler->initialWorkerTarget(8))
            ->because('the initial target MUST use the shared resource capacity')
            ->toBe(2);
    }

    #[Test]
    public function initialWorkerTargetKeepsAllAchievableParallelism(): void
    {
        $scheduler = new ResourceScheduler([
            SchedulingFixture::unit('UnconstrainedTest'),
            SchedulingFixture::unit('DatabaseTest', ['database']),
            SchedulingFixture::unit('ApiTest', ['api']),
            SchedulingFixture::unit('CombinedTest', ['database', 'api']),
        ], [], ['database' => 2, 'api' => 1]);

        Expect::that($scheduler->initialWorkerTarget(8))
            ->because('the initial target MUST keep unconstrained and separate resource capacity')
            ->toBe(4);
        Expect::that($scheduler->initialWorkerTarget(2))
            ->because('the configured worker count MUST remain the concurrency ceiling')
            ->toBe(2);
    }

    #[Test]
    public function unconfiguredResourcesAreExclusive(): void
    {
        $scheduler = new ResourceScheduler([
            SchedulingFixture::unit('FirstTest', ['postgres']),
            SchedulingFixture::unit('SecondTest', ['postgres']),
        ], [], []);

        $first = SchedulingFixture::assignedLease($scheduler);

        Expect::that($scheduler->dispatch(true)->kind)->because('unconfigured resources are exclusive')->toBe(DispatchKind::Wait);

        $scheduler->release($first);

        Expect::that(SchedulingFixture::assignedLease($scheduler)->unit->plan->classes())->because('unconfigured resources are exclusive')->toBe(['SecondTest']);
    }

    #[Test]
    public function configuredLimitsPermitThatManyConcurrentLeases(): void
    {
        $scheduler = new ResourceScheduler([
            SchedulingFixture::unit('FirstTest', ['postgres']),
            SchedulingFixture::unit('SecondTest', ['postgres']),
            SchedulingFixture::unit('ThirdTest', ['postgres']),
        ], [], ['postgres' => 2]);

        SchedulingFixture::assignedLease($scheduler);
        SchedulingFixture::assignedLease($scheduler);

        Expect::that($scheduler->dispatch(true)->kind)->because('configured limits permit that many concurrent leases')->toBe(DispatchKind::Wait);
    }

    #[Test]
    public function oldestBlockedUnitReservesItsResourcesButDisjointWorkCanBypassIt(): void
    {
        $scheduler = new ResourceScheduler([
            SchedulingFixture::unit('BHoldingTest', ['b']),
            SchedulingFixture::unit('NeedsBothTest', ['a', 'b']),
            SchedulingFixture::unit('NeedsATest', ['a']),
            SchedulingFixture::unit('DisjointTest', ['c']),
        ], [], []);

        $holdingB = SchedulingFixture::assignedLease($scheduler);
        $disjoint = SchedulingFixture::assignedLease($scheduler);

        Expect::that($disjoint->unit->plan->classes())->because('oldest blocked unit reserves its resources but disjoint work can bypass it')->toBe(['DisjointTest']);
        Expect::that($scheduler->dispatch(true)->kind)->because('oldest blocked unit reserves its resources but disjoint work can bypass it')->toBe(DispatchKind::Wait);

        $scheduler->release($holdingB);

        Expect::that(SchedulingFixture::assignedLease($scheduler)->unit->plan->classes())->because('oldest blocked unit reserves its resources but disjoint work can bypass it')->toBe(['NeedsBothTest']);
    }

    #[Test]
    public function workCanUseCapacityBeyondTheOldestBlockedUnitReservation(): void
    {
        $scheduler = new ResourceScheduler([
            SchedulingFixture::unit('BHoldingTest', ['b']),
            SchedulingFixture::unit('NeedsBothTest', ['a', 'b']),
            SchedulingFixture::unit('FirstNeedsATest', ['a']),
            SchedulingFixture::unit('SecondNeedsATest', ['a']),
        ], [], ['a' => 2]);

        SchedulingFixture::assignedLease($scheduler);

        Expect::that(SchedulingFixture::assignedLease($scheduler)->unit->plan->classes())->because('work can use capacity beyond the oldest blocked unit reservation')->toBe(['FirstNeedsATest']);
        Expect::that($scheduler->dispatch(true)->kind)->because('work can use capacity beyond the oldest blocked unit reservation')->toBe(DispatchKind::Wait);
    }

    #[Test]
    public function isolatedUnitsNeedAFreshWorkerAndRunAfterThePooledQueue(): void
    {
        $isolated = SchedulingFixture::unit('IsolatedTest', ['database'], isolated: true);
        $scheduler = new ResourceScheduler([], [$isolated], []);

        Expect::that($scheduler->dispatch(false)->kind)->because('isolated units need a fresh worker and run after the pooled queue')->toBe(DispatchKind::Drain);
        Expect::that(SchedulingFixture::assignedLease($scheduler, freshWorker: true)->unit)->because('isolated units need a fresh worker and run after the pooled queue')->toBe($isolated);
    }

    #[Test]
    public function requeuedIsolatedUnitsStillNeedAFreshWorker(): void
    {
        $isolated = SchedulingFixture::unit('IsolatedTest', ['database'], isolated: true);
        $scheduler = new ResourceScheduler([], [], []);

        $scheduler->requeue($isolated);

        Expect::that($scheduler->dispatch(false)->kind)->because('requeued isolated units still need a fresh worker')->toBe(DispatchKind::Drain);
        Expect::that(SchedulingFixture::assignedLease($scheduler, freshWorker: true)->unit)->because('requeued isolated units still need a fresh worker')->toBe($isolated);
    }

    #[Test]
    public function releasedLeasesCannotBeReleasedTwice(): void
    {
        $scheduler = new ResourceScheduler([SchedulingFixture::unit('OnlyTest', ['database'])], [], []);
        $lease = SchedulingFixture::assignedLease($scheduler);
        $scheduler->release($lease);

        Expect::that(static fn() => $scheduler->release($lease))->because('released leases cannot be released twice')
            ->toThrow(\LogicException::class, matching: '/already been released/');
    }

}
