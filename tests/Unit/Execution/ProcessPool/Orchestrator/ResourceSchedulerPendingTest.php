<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\DispatchKind;
use Greenlight\Execution\ProcessPool\Orchestrator\ResourceScheduler;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\SchedulingFixture;

final class ResourceSchedulerPendingTest
{
    #[Test]
    public function clearingPendingWorkPreservesActiveLeasesAndAllowsRequeue(): void
    {
        $active = SchedulingFixture::unit('ActiveTest', ['database']);
        $queued = SchedulingFixture::unit('QueuedTest', ['database']);
        $isolated = SchedulingFixture::unit('IsolatedTest', ['browser'], isolated: true);
        $scheduler = new ResourceScheduler([$active, $queued], [$isolated], []);

        Expect::that($scheduler->pendingCount())
            ->because('pending count MUST include pooled and isolated work')
            ->toBe(3);

        $lease = SchedulingFixture::assignedLease($scheduler);

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
        Expect::that(SchedulingFixture::assignedLease($scheduler, freshWorker: false)->unit)->toBe($queued);
        Expect::that($scheduler->pendingCount())->toBe(0);
    }
}
