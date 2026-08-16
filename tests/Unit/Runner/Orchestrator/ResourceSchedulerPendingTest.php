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

final class ResourceSchedulerPendingTest
{
    #[Test]
    public function clearingPendingWorkPreservesActiveLeasesAndAllowsRequeue(): void
    {
        $active = $this->unit('ActiveTest', ['database']);
        $queued = $this->unit('QueuedTest', ['database']);
        $isolated = $this->unit('IsolatedTest', ['browser'], isolated: true);
        $scheduler = new ResourceScheduler([$active, $queued], [$isolated], []);

        Expect::that($scheduler->pendingCount())
            ->because('pending count MUST include pooled and isolated work')
            ->toBe(3);

        $lease = $this->assigned($scheduler);

        Expect::that($scheduler->pendingCount())
            ->because('assignment MUST remove one pending unit')
            ->toBe(2);

        $scheduler->clearPending();

        Expect::that($scheduler->pendingCount())
            ->because('clear pending MUST remove both queues')
            ->toBe(0);
        Expect::that($scheduler->dispatch(true)->kind)->toBe(DispatchKind::Drain);

        $scheduler->release($lease);
        $scheduler->requeue($queued);

        Expect::that($scheduler->pendingCount())
            ->because('pooled work can be requeued after pending work is cleared')
            ->toBe(1);
        Expect::that($this->assigned($scheduler, fresh: false)->unit)->toBe($queued);
        Expect::that($scheduler->pendingCount())->toBe(0);
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

        if (!$decision->lease instanceof ResourceLease) {
            Fail::because(\sprintf('Expected an assignment, got %s.', $decision->kind->name));
        }

        return $decision->lease;
    }
}
