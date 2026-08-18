<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\ResourceScheduler;
use Greenlight\Tests\Support\SchedulingFixture;

final readonly class ResourceSchedulerRequeueOrderTest
{
    #[Test]
    #[DataSet('queueKinds')]
    public function requeuedWorkAppendsBehindSparsePendingWork(bool $isolated, bool $freshWorker): void
    {
        $active = SchedulingFixture::unit('Acme\\ActiveTest', ['database'], $isolated);
        $pending = SchedulingFixture::unit('Acme\\PendingTest', ['database'], $isolated);
        $requeued = SchedulingFixture::unit('Acme\\RetriedTest', ['database'], $isolated);
        $scheduler = new ResourceScheduler(
            $isolated ? [] : [$active, $pending],
            $isolated ? [$active, $pending] : [],
            [],
        );
        $activeLease = SchedulingFixture::assignedLease($scheduler, $freshWorker);
        $scheduler->release($activeLease);
        $scheduler->requeue($requeued);

        $first = SchedulingFixture::assignedLease($scheduler, $freshWorker);
        $scheduler->release($first);
        $second = SchedulingFixture::assignedLease($scheduler, $freshWorker);

        Expect::that($first->unit->plan->classes())
            ->because('pending work MUST remain first in its queue')
            ->toBe(['Acme\\PendingTest']);
        Expect::that($second->unit->plan->classes())
            ->because('requeued work MUST append behind pending work in its queue')
            ->toBe(['Acme\\RetriedTest']);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function queueKinds(): iterable
    {
        yield 'pooled queue' => [false, false];
        yield 'isolated queue' => [true, true];
    }

}
