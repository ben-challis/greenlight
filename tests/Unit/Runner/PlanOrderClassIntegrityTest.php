<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\PlanOrder;

final class PlanOrderClassIntegrityTest
{
    #[Test]
    public function classPriorityPreservesMethodOrderAndThePlanSeed(): void
    {
        $plan = new ExecutionPlan([
            $this->entry('Acme\\AlphaTest', 'first'),
            $this->entry('Acme\\AlphaTest', 'second'),
            $this->entry('Acme\\BetaTest', 'first'),
            $this->entry('Acme\\BetaTest', 'second'),
        ], seed: 4242);

        $ordered = PlanOrder::schedule($plan, ['Acme\\BetaTest'], []);
        $ids = \array_map(
            static fn(PlanEntry $entry): string => (string) $entry->id,
            $ordered->entries,
        );

        Expect::that([$ids, $ordered->seed])
            ->because('class priority MUST preserve method order and the reproducible plan seed')
            ->toBe([
                [
                    'Acme\\BetaTest::first',
                    'Acme\\BetaTest::second',
                    'Acme\\AlphaTest::first',
                    'Acme\\AlphaTest::second',
                ],
                4242,
            ]);
    }

    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     */
    private function entry(string $class, string $method): PlanEntry
    {
        return new PlanEntry(
            new TestId($class, $method),
            new TestMetadata($class, $method),
        );
    }
}
