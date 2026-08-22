<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanOrder;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PlanEntryFixture;

final readonly class PlanOrderZeroDurationTest
{
    #[Test]
    public function zeroDurationRemainsKnown(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('Acme\\UnknownTest'),
            PlanEntryFixture::create('Acme\\InstantTest'),
        ]);

        $ordered = PlanOrder::schedule($plan, [], [
            'Acme\\InstantTest' => 0.0,
        ]);

        Expect::that($ordered->classes())
            ->because('a zero duration MUST remain known and precede classes without timing data')
            ->toBe([
                'Acme\\InstantTest',
                'Acme\\UnknownTest',
            ]);
    }
}
