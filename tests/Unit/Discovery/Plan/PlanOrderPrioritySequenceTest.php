<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery\Plan;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanOrder;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PlanEntryFixture;

final class PlanOrderPrioritySequenceTest
{
    #[Test]
    public function distinctPriorityClassesRetainTheCallerSequence(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('Acme\\AlphaTest', 'probe'),
            PlanEntryFixture::create('Acme\\BetaTest', 'probe'),
            PlanEntryFixture::create('Acme\\GammaTest', 'probe'),
            PlanEntryFixture::create('Acme\\DeltaTest', 'probe'),
        ], seed: 4242);

        $ordered = PlanOrder::schedule(
            $plan,
            priorityClasses: ['Acme\\GammaTest', 'Acme\\AlphaTest'],
            classSeconds: [
                'Acme\\BetaTest' => 1.0,
                'Acme\\DeltaTest' => 2.0,
            ],
        );

        Expect::that($ordered->classes())
            ->because('priority classes MUST retain the caller sequence before duration ordering')
            ->toBe([
                'Acme\\GammaTest',
                'Acme\\AlphaTest',
                'Acme\\DeltaTest',
                'Acme\\BetaTest',
            ]);
        Expect::that($ordered->seed)
            ->because('priority ordering MUST preserve the plan seed')
            ->toBe(4242);
    }
}
