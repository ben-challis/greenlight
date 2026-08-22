<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanOrder;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PlanEntryFixture;

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

        Expect::that($ordered->classes())
            ->because('equal recorded durations MUST preserve discovery order')
            ->toBe([
                'Acme\\GammaTest',
                'Acme\\AlphaTest',
                'Acme\\BetaTest',
            ]);
    }
}
