<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\Distributor;
use Greenlight\Runner\Orchestrator\SchedulingUnit;

final readonly class DistributorPartitionTest
{
    #[Test]
    public function mixedEntriesRemainCompleteAndOrdered(): void
    {
        $plan = new ExecutionPlan([
            $this->entry('Acme\\AlphaTest', 'first'),
            $this->entry('Acme\\AlphaTest', 'isolated', isolated: true),
            $this->entry('Acme\\AlphaTest', 'third'),
            $this->entry('Acme\\BetaTest', 'isolated', isolated: true),
            $this->entry('Acme\\BetaTest', 'second'),
        ], seed: 4242);

        [$pooled, $isolated] = new Distributor()->units($plan);

        Expect::that([
            'pooled' => \array_map($this->unitShape(...), $pooled),
            'isolated' => \array_map($this->unitShape(...), $isolated),
        ])
            ->because('distribution MUST preserve every entry, its order, and the plan seed')
            ->toBe([
                'pooled' => [
                    [
                        'ids' => ['Acme\\AlphaTest::first', 'Acme\\AlphaTest::third'],
                        'seed' => 4242,
                        'isolated' => false,
                    ],
                    [
                        'ids' => ['Acme\\BetaTest::second'],
                        'seed' => 4242,
                        'isolated' => false,
                    ],
                ],
                'isolated' => [
                    [
                        'ids' => ['Acme\\AlphaTest::isolated'],
                        'seed' => 4242,
                        'isolated' => true,
                    ],
                    [
                        'ids' => ['Acme\\BetaTest::isolated'],
                        'seed' => 4242,
                        'isolated' => true,
                    ],
                ],
            ]);
    }

    /**
     * @return array{ids: list<string>, seed: int|null, isolated: bool}
     */
    private function unitShape(SchedulingUnit $unit): array
    {
        return [
            'ids' => \array_map(
                static fn(PlanEntry $entry): string => (string) $entry->id,
                $unit->plan->entries,
            ),
            'seed' => $unit->plan->seed,
            'isolated' => $unit->isolated,
        ];
    }

    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     */
    private function entry(string $class, string $method, bool $isolated = false): PlanEntry
    {
        return new PlanEntry(
            new TestId($class, $method),
            new TestMetadata($class, $method, isolated: $isolated),
        );
    }
}
