<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\PlanOrder;

final class PlanOrderStalePriorityTest
{
    #[Test]
    public function aStalePriorityClassIsIgnored(): void
    {
        $plan = new ExecutionPlan([
            $this->entry('Acme\\AlphaTest'),
            $this->entry('Acme\\BetaTest'),
        ], seed: 4242);

        $ordered = ErrorTrap::run(
            static fn(): ExecutionPlan => PlanOrder::schedule($plan, ['Acme\\RemovedTest'], []),
            $warning,
        );
        $ids = \array_map(
            static fn(PlanEntry $entry): string => (string) $entry->id,
            $ordered->entries,
        );

        Expect::that([$ids, $ordered->seed, $warning])
            ->because('stale priority data MUST NOT change the current plan')
            ->toBe([
                ['Acme\\AlphaTest::probe', 'Acme\\BetaTest::probe'],
                4242,
                null,
            ]);
    }

    /**
     * @param non-empty-string $class
     */
    private function entry(string $class): PlanEntry
    {
        return new PlanEntry(
            new TestId($class, 'probe'),
            new TestMetadata($class, 'probe'),
        );
    }
}
