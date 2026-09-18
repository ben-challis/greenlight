<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery\Plan;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanOrder;
use Greenlight\Tests\Support\PlanEntryFixture;

use function Greenlight\expect;

final readonly class PlanOrderDurationTieTest
{
    #[Test]
    public function equalDurationsPreserveDiscoveryOrder(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('Acme\\GammaTest'),
            PlanEntryFixture::create('Acme\\AlphaTest'),
            PlanEntryFixture::create('Acme\\BetaTest'),
        ]);

        $ordered = PlanOrder::schedule($plan, [], [
            'Acme\\BetaTest' => 1.0,
            'Acme\\AlphaTest' => 1.0,
            'Acme\\GammaTest' => 1.0,
        ]);

        expect($ordered->classes())
            ->because('equal recorded durations MUST preserve discovery order')
            ->toBe([
                'Acme\\GammaTest',
                'Acme\\AlphaTest',
                'Acme\\BetaTest',
            ]);
    }
}
