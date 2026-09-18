<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\DispatchKind;
use Greenlight\Execution\ProcessPool\Orchestrator\ResourceScheduler;
use Greenlight\Tests\Support\SchedulingFixture;

use function Greenlight\expect;

final class ResourceSchedulerPendingTest
{
    #[Test]
    public function clearingPendingWorkPreservesActiveLeasesAndAllowsRequeue(): void
    {
        $active = SchedulingFixture::unit('ActiveTest', ['database']);
        $queued = SchedulingFixture::unit('QueuedTest', ['database']);
        $isolated = SchedulingFixture::unit('IsolatedTest', ['browser'], isolated: true);
        $scheduler = new ResourceScheduler([$active, $queued], [$isolated], []);

        expect($scheduler->pendingCount())
            ->because('pending count MUST include pooled and isolated work')
            ->toBe(3);

        $lease = SchedulingFixture::assignedLease($scheduler);

        expect($scheduler->pendingCount())
            ->because('assignment MUST remove one pending unit')
            ->toBe(2);

        $scheduler->clearPending();

        expect($scheduler->pendingCount())
            ->because('clear pending MUST remove both queues')
            ->toBe(0);
        expect($scheduler->dispatch(true)->kind)->toBe(DispatchKind::Drain);

        $scheduler->release($lease);
        $scheduler->requeue($queued);

        expect($scheduler->pendingCount())
            ->because('pooled work can be requeued after pending work is cleared')
            ->toBe(1);
        expect(SchedulingFixture::assignedLease($scheduler, freshWorker: false)->unit)->toBe($queued);
        expect($scheduler->pendingCount())->toBe(0);
    }
}
