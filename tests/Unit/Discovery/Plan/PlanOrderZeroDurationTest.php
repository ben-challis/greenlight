<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery\Plan;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanOrder;
use Greenlight\Tests\Support\PlanEntryFixture;

use function Greenlight\expect;

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

        expect($ordered->classes())
            ->because('a zero duration MUST remain known and precede classes without timing data')
            ->toBe([
                'Acme\\InstantTest',
                'Acme\\UnknownTest',
            ]);
    }
}
