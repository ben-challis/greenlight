<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\DispatchKind;
use Greenlight\Execution\ProcessPool\Orchestrator\ResourceScheduler;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\SchedulingFixture;

final readonly class ResourceLeaseIdentityTest
{
    #[Test]
    public function concurrentLeasesHaveDistinctIdentityAndReleaseIndependently(): void
    {
        $thirdUnit = SchedulingFixture::unit('Acme\\ThirdTest', ['database']);
        $fourthUnit = SchedulingFixture::unit('Acme\\FourthTest', ['database']);
        $scheduler = new ResourceScheduler([
            SchedulingFixture::unit('Acme\\FirstTest', ['database']),
            SchedulingFixture::unit('Acme\\SecondTest', ['database']),
            $thirdUnit,
            $fourthUnit,
        ], [], ['database' => 2]);

        $first = SchedulingFixture::assignedLease($scheduler);
        $second = SchedulingFixture::assignedLease($scheduler);

        Expect::that($first->id)
            ->because('the first resource lease MUST use the first lease ID')
            ->toBe(1);
        Expect::that($second->id)
            ->because('the second resource lease MUST use a distinct lease ID')
            ->toBe(2);

        Expect::that($scheduler->dispatch(true)->kind)
            ->because('two concurrent resource leases MUST use all configured capacity')
            ->toBe(DispatchKind::Wait);

        $scheduler->release($first);

        Expect::that(SchedulingFixture::assignedLease($scheduler)->unit)
            ->because('the first release MUST restore one resource slot')
            ->toBe($thirdUnit);
        Expect::that($scheduler->dispatch(true)->kind)
            ->because('the first release MUST restore only one resource slot')
            ->toBe(DispatchKind::Wait);

        $scheduler->release($second);

        Expect::that(SchedulingFixture::assignedLease($scheduler)->unit)
            ->because('the second release MUST independently restore one resource slot')
            ->toBe($fourthUnit);
    }

}
