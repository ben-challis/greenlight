<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\PlanOrder;
use Greenlight\Tests\Support\PlanEntryFixture;

final class PlanOrderClassIntegrityTest
{
    #[Test]
    public function classPriorityPreservesMethodOrderAndThePlanSeed(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('Acme\\AlphaTest', 'first'),
            PlanEntryFixture::create('Acme\\AlphaTest', 'second'),
            PlanEntryFixture::create('Acme\\BetaTest', 'first'),
            PlanEntryFixture::create('Acme\\BetaTest', 'second'),
        ], seed: 4242);

        $ordered = PlanOrder::schedule($plan, ['Acme\\BetaTest'], []);
        $ids = \array_map(
            static fn(PlanEntry $entry): string => (string) $entry->id,
            $ordered->entries,
        );

        Expect::that($ids)
            ->because('class priority MUST preserve method order')
            ->toBe([
                'Acme\\BetaTest::first',
                'Acme\\BetaTest::second',
                'Acme\\AlphaTest::first',
                'Acme\\AlphaTest::second',
            ]);
        Expect::that($ordered->seed)
            ->because('class priority MUST preserve the reproducible plan seed')
            ->toBe(4242);
    }
}
