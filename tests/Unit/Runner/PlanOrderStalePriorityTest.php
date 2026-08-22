<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Runner\PlanOrder;
use Greenlight\Tests\Support\PlanEntryFixture;

final class PlanOrderStalePriorityTest
{
    #[Test]
    public function aStalePriorityClassIsIgnored(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('Acme\\AlphaTest', 'probe'),
            PlanEntryFixture::create('Acme\\BetaTest', 'probe'),
        ], seed: 4242);

        $ordered = ErrorTrap::run(
            static fn() => PlanOrder::schedule($plan, ['Acme\\RemovedTest'], []),
            $warning,
        );
        $ids = \array_map(
            static fn(PlanEntry $entry): string => (string) $entry->id,
            $ordered->entries,
        );

        Expect::that($ids)
            ->because('stale priority data MUST NOT change the current plan entries')
            ->toBe(['Acme\\AlphaTest::probe', 'Acme\\BetaTest::probe']);
        Expect::that($ordered->seed)
            ->because('stale priority data MUST NOT change the plan seed')
            ->toBe(4242);
        Expect::that($warning)
            ->because('stale priority data MUST NOT cause a warning')
            ->toBeNull();
    }
}
