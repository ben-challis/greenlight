<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;

final readonly class ExecutionPlanGroupingTest
{
    #[Test]
    public function groupingPreservesClassAndTestOrder(): void
    {
        $plan = new ExecutionPlan([
            $this->entry('Acme\\AlphaTest', 'first'),
            $this->entry('Acme\\AlphaTest', 'second', 'row two'),
            $this->entry('Acme\\BetaTest', 'third'),
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

    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     */
    private function entry(string $class, string $method, ?string $dataSetKey = null): PlanEntry
    {
        return new PlanEntry(
            new TestId($class, $method, $dataSetKey),
            new TestMetadata($class, $method),
        );
    }
}
