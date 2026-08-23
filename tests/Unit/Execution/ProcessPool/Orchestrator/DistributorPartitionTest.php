<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Execution\ProcessPool\Orchestrator\Distributor;
use Greenlight\Execution\ProcessPool\Orchestrator\SchedulingUnit;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PlanEntryFixture;

final readonly class DistributorPartitionTest
{
    #[Test]
    public function mixedEntriesRemainCompleteAndOrdered(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('Acme\\AlphaTest', 'first'),
            PlanEntryFixture::create('Acme\\AlphaTest', 'isolated', isolated: true),
            PlanEntryFixture::create('Acme\\AlphaTest', 'third'),
            PlanEntryFixture::create('Acme\\BetaTest', 'isolated', isolated: true),
            PlanEntryFixture::create('Acme\\BetaTest', 'second'),
        ], seed: 4242);

        [$pooled, $isolated] = new Distributor()->units($plan);

        Expect::that(\array_map($this->unitShape(...), $pooled))
            ->because('pooled distribution MUST preserve every entry, its order, and the plan seed')
            ->toBe([
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
            ]);
        Expect::that(\array_map($this->unitShape(...), $isolated))
            ->because('isolated distribution MUST preserve every entry, its order, and the plan seed')
            ->toBe([
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
}
