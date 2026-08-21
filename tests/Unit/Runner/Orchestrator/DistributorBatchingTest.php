<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\Distributor;
use Greenlight\Runner\Orchestrator\SchedulingUnit;
use Greenlight\Tests\Support\PlanEntryFixture;

final readonly class DistributorBatchingTest
{
    #[Test]
    public function tinyClassesWithTheSameResourcesShareABoundedUnit(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('Acme\\AlphaTest', resources: ['redis', 'postgres']),
            PlanEntryFixture::create('Acme\\BetaTest', resources: ['postgres', 'redis']),
            PlanEntryFixture::create('Acme\\GammaTest', resources: ['redis', 'postgres']),
        ]);

        [$pooled, $isolated] = new Distributor()->units($plan, [
            'Acme\\AlphaTest' => 0.02,
            'Acme\\BetaTest' => 0.02,
            'Acme\\GammaTest' => 0.02,
        ]);

        Expect::that($this->classShape($pooled))
            ->because('a batch MUST preserve class order and stay below its predicted duration limit')
            ->toBe([
                ['Acme\\AlphaTest', 'Acme\\BetaTest'],
                ['Acme\\GammaTest'],
            ]);
        Expect::that($pooled[0]->resources)
            ->because('resource order MUST NOT change resource-set compatibility')
            ->toBe(['redis', 'postgres']);
        Expect::that($isolated)->toBe([]);
    }

    #[Test]
    public function incompatibleAndUnknownClassesKeepOneClassUnits(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('Acme\\FreeTest'),
            PlanEntryFixture::create('Acme\\PostgresTest', resources: ['postgres']),
            PlanEntryFixture::create('Acme\\RedisTest', resources: ['redis']),
            PlanEntryFixture::create('Acme\\UnknownTest'),
            PlanEntryFixture::create('Acme\\LargeTest'),
        ]);

        [$pooled] = new Distributor()->units($plan, [
            'Acme\\FreeTest' => 0.001,
            'Acme\\PostgresTest' => 0.001,
            'Acme\\RedisTest' => 0.001,
            'Acme\\LargeTest' => 0.051,
        ]);

        Expect::that($this->classShape($pooled))
            ->because('unsafe duration or resource combinations MUST keep one-class assignments')
            ->toBe([
                ['Acme\\FreeTest'],
                ['Acme\\PostgresTest'],
                ['Acme\\RedisTest'],
                ['Acme\\UnknownTest'],
                ['Acme\\LargeTest'],
            ]);
    }

    #[Test]
    public function classesWithIsolatedEntriesDoNotJoinBatches(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('Acme\\MixedTest', 'pooled'),
            PlanEntryFixture::create('Acme\\MixedTest', 'isolated', isolated: true),
            PlanEntryFixture::create('Acme\\NextTest'),
        ]);

        [$pooled, $isolated] = new Distributor()->units($plan, [
            'Acme\\MixedTest' => 0.001,
            'Acme\\NextTest' => 0.001,
        ]);

        Expect::that($this->classShape($pooled))
            ->because('a mixed-isolation class has no safe pooled duration estimate')
            ->toBe([
                ['Acme\\MixedTest'],
                ['Acme\\NextTest'],
            ]);
        Expect::that($this->classShape($isolated))
            ->because('an isolated entry MUST remain a separate unit')
            ->toBe([['Acme\\MixedTest']]);
    }

    #[Test]
    public function allowParallelEntriesDoNotJoinBatches(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('Acme\\ParallelTest', 'first', allowParallel: true),
            PlanEntryFixture::create('Acme\\ParallelTest', 'second', allowParallel: true),
            PlanEntryFixture::create('Acme\\NextTest'),
        ]);

        [$pooled] = new Distributor()->units($plan, [
            'Acme\\ParallelTest' => 0.001,
            'Acme\\NextTest' => 0.001,
        ]);

        Expect::that($this->classShape($pooled))
            ->because('opted-in entries MUST remain separate scheduling units')
            ->toBe([
                ['Acme\\ParallelTest'],
                ['Acme\\ParallelTest'],
                ['Acme\\NextTest'],
            ]);
    }

    #[Test]
    public function seededPlansPreserveTheirClassOrderAndSeed(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('Acme\\AlphaTest'),
            PlanEntryFixture::create('Acme\\BetaTest'),
        ], seed: 4242);

        [$pooled] = new Distributor()->units($plan, [
            'Acme\\AlphaTest' => 0.001,
            'Acme\\BetaTest' => 0.001,
        ]);

        Expect::that($this->classShape($pooled))
            ->because('batching a seeded plan MUST preserve its class order')
            ->toBe([['Acme\\AlphaTest', 'Acme\\BetaTest']]);
        Expect::that($pooled[0]->plan->seed)->toBe(4242);
    }

    #[Test]
    public function manyZeroDurationClassesKeepWorkerCapacityAndLimitBatchSize(): void
    {
        $entries = [];
        $durations = [];

        for ($index = 0; $index < 64; ++$index) {
            $class = \sprintf('Acme\\Fast%02dTest', $index);
            $entries[] = PlanEntryFixture::create($class);
            $durations[$class] = 0.0;
        }

        [$pooled] = new Distributor()->units(new ExecutionPlan($entries), $durations, workerCount: 4);

        Expect::that($pooled)
            ->because('many fast classes SHOULD need fewer assignments without reducing worker capacity')
            ->toHaveCount(4);

        foreach ($pooled as $unit) {
            Expect::that($unit->plan->classes())
                ->because('a stale zero-duration estimate MUST NOT create a large tail unit')
                ->toHaveCount(16);
        }

        Expect::that(\array_merge(...$this->classShape($pooled)))
            ->because('batching MUST preserve every class in plan order')
            ->toBe(\array_keys($durations));
    }

    #[Test]
    public function eachCompatibleResourceRunKeepsWorkerCapacity(): void
    {
        $entries = [];
        $durations = [];

        foreach (['FreeA', 'FreeB', 'FreeC', 'FreeD', 'FreeE', 'FreeF', 'FreeG', 'FreeH'] as $name) {
            $class = 'Acme\\' . $name . 'Test';
            $entries[] = PlanEntryFixture::create($class);
            $durations[$class] = 0.001;
        }

        foreach (['DbA', 'DbB', 'DbC', 'DbD'] as $name) {
            $class = 'Acme\\' . $name . 'Test';
            $entries[] = PlanEntryFixture::create($class, resources: ['postgres']);
            $durations[$class] = 0.001;
        }

        [$pooled] = new Distributor()->units(new ExecutionPlan($entries), $durations, workerCount: 4);

        Expect::that($this->classShape($pooled))
            ->because('batching MUST preserve worker capacity for each resource-compatible run')
            ->toBe([
                ['Acme\\FreeATest', 'Acme\\FreeBTest', 'Acme\\FreeCTest', 'Acme\\FreeDTest', 'Acme\\FreeETest'],
                ['Acme\\FreeFTest'],
                ['Acme\\FreeGTest'],
                ['Acme\\FreeHTest'],
                ['Acme\\DbATest'],
                ['Acme\\DbBTest'],
                ['Acme\\DbCTest'],
                ['Acme\\DbDTest'],
            ]);
    }

    /**
     * @param list<SchedulingUnit> $units
     *
     * @return list<list<non-empty-string>>
     */
    private function classShape(array $units): array
    {
        return \array_map(
            static fn(SchedulingUnit $unit): array => $unit->plan->classes(),
            $units,
        );
    }
}
