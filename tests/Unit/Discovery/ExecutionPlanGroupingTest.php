<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PlanEntryFixture;

final readonly class ExecutionPlanGroupingTest
{
    #[Test]
    public function groupingPreservesClassAndTestOrder(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('Acme\\AlphaTest', 'first'),
            PlanEntryFixture::create('Acme\\AlphaTest', 'second', 'row two'),
            PlanEntryFixture::create('Acme\\BetaTest', 'third'),
        ]);

        $idsByClass = \array_map(
            static fn(array $entries): array => \array_map(
                static fn(PlanEntry $entry): string => (string) $entry->id,
                $entries,
            ),
            $plan->entriesByClass(),
        );

        Expect::that($idsByClass)
            ->because('grouping MUST preserve class, method, and data-set order')
            ->toBe([
                'Acme\\AlphaTest' => [
                    'Acme\\AlphaTest::first',
                    'Acme\\AlphaTest::second[row two]',
                ],
                'Acme\\BetaTest' => [
                    'Acme\\BetaTest::third',
                ],
            ]);
    }
}
