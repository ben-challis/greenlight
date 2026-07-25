<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\DispatchKind;
use Greenlight\Runner\Orchestrator\ResourceLease;
use Greenlight\Runner\Orchestrator\ResourceScheduler;
use Greenlight\Runner\Orchestrator\SchedulingUnit;

final class ResourceSchedulerTest
{
    #[Test]
    public function unconfiguredResourcesAreExclusive(): void
    {
        $scheduler = new ResourceScheduler([
            $this->unit('FirstTest', ['postgres']),
            $this->unit('SecondTest', ['postgres']),
        ], [], []);

        $first = $this->assigned($scheduler);

        Expect::that($scheduler->dispatch(true)->kind)->toBe(DispatchKind::Wait);

        $scheduler->release($first);

        Expect::that($this->assigned($scheduler)->unit->plan->classes())->toBe(['SecondTest']);
    }

    #[Test]
    public function configuredLimitsPermitThatManyConcurrentLeases(): void
    {
        $scheduler = new ResourceScheduler([
            $this->unit('FirstTest', ['postgres']),
            $this->unit('SecondTest', ['postgres']),
            $this->unit('ThirdTest', ['postgres']),
        ], [], ['postgres' => 2]);

        $this->assigned($scheduler);
        $this->assigned($scheduler);

        Expect::that($scheduler->dispatch(true)->kind)->toBe(DispatchKind::Wait);
    }

    #[Test]
    public function oldestBlockedUnitReservesItsResourcesButDisjointWorkCanBypassIt(): void
    {
        $scheduler = new ResourceScheduler([
            $this->unit('BHoldingTest', ['b']),
            $this->unit('NeedsBothTest', ['a', 'b']),
            $this->unit('NeedsATest', ['a']),
            $this->unit('DisjointTest', ['c']),
        ], [], []);

        $holdingB = $this->assigned($scheduler);
        $disjoint = $this->assigned($scheduler);

        Expect::that($disjoint->unit->plan->classes())->toBe(['DisjointTest']);
        Expect::that($scheduler->dispatch(true)->kind)->toBe(DispatchKind::Wait);

        $scheduler->release($holdingB);

        Expect::that($this->assigned($scheduler)->unit->plan->classes())->toBe(['NeedsBothTest']);
    }

    #[Test]
    public function workCanUseCapacityBeyondTheOldestBlockedUnitReservation(): void
    {
        $scheduler = new ResourceScheduler([
            $this->unit('BHoldingTest', ['b']),
            $this->unit('NeedsBothTest', ['a', 'b']),
            $this->unit('FirstNeedsATest', ['a']),
            $this->unit('SecondNeedsATest', ['a']),
        ], [], ['a' => 2]);

        $this->assigned($scheduler);

        Expect::that($this->assigned($scheduler)->unit->plan->classes())->toBe(['FirstNeedsATest']);
        Expect::that($scheduler->dispatch(true)->kind)->toBe(DispatchKind::Wait);
    }

    #[Test]
    public function isolatedUnitsNeedAFreshWorkerAndRunAfterThePooledQueue(): void
    {
        $isolated = $this->unit('IsolatedTest', ['database'], isolated: true);
        $scheduler = new ResourceScheduler([], [$isolated], []);

        Expect::that($scheduler->dispatch(false)->kind)->toBe(DispatchKind::Drain);
        Expect::that($this->assigned($scheduler, fresh: true)->unit)->toBe($isolated);
    }

    #[Test]
    public function releasedLeasesCannotBeReleasedTwice(): void
    {
        $scheduler = new ResourceScheduler([$this->unit('OnlyTest', ['database'])], [], []);
        $lease = $this->assigned($scheduler);
        $scheduler->release($lease);

        Expect::that(static fn() => $scheduler->release($lease))
            ->toThrow(\LogicException::class, matching: '/already been released/');
    }

    /**
     * @param non-empty-string $class
     * @param list<non-empty-string> $resources
     */
    private function unit(string $class, array $resources, bool $isolated = false): SchedulingUnit
    {
        $id = new TestId($class, 'runs');

        return new SchedulingUnit(new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($class, 'runs', resources: $resources)),
        ]), $isolated);
    }

    private function assigned(ResourceScheduler $scheduler, bool $fresh = true): ResourceLease
    {
        $decision = $scheduler->dispatch($fresh);

        Expect::that($decision->kind)->toBe(DispatchKind::Assign);
        \assert($decision->lease instanceof ResourceLease);

        return $decision->lease;
    }
}
