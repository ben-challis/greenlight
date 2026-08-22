<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\ResourceLease;
use Greenlight\Execution\ProcessPool\Orchestrator\ResourceScheduler;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\SchedulingFixture;

final readonly class ResourceSchedulerLeaseIdentityTest
{
    #[Test]
    public function aLeaseIdDoesNotAuthorizeADifferentLeaseObject(): void
    {
        $first = SchedulingFixture::unit('Acme\\FirstTest', ['database']);
        $second = SchedulingFixture::unit('Acme\\SecondTest', ['database']);
        $scheduler = new ResourceScheduler([$first, $second], [], []);
        $lease = SchedulingFixture::assignedLease($scheduler);
        $forged = new ResourceLease($lease->id, $lease->unit);

        Expect::that(static fn() => $scheduler->release($forged))
            ->because('only the exact lease object MUST release its resource slots')
            ->toThrow(
                \LogicException::class,
                message: 'Resource lease 1 is unknown or has already been released.',
            );

        Expect::that(static fn() => $scheduler->release($lease))
            ->because('rejecting a forged lease MUST preserve the real lease')
            ->not()
            ->toThrow(\Throwable::class);

        Expect::that(SchedulingFixture::assignedLease($scheduler)->unit)
            ->because('the real lease MUST release its resource slot')
            ->toBe($second);
    }

}
