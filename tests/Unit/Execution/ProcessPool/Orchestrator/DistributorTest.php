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

final class DistributorTest
{
    #[Test]
    public function isolatedEntriesBecomeSingletonUnitsInPlanOrder(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('ExampleTest', 'pooled'),
            PlanEntryFixture::create('ExampleTest', 'isolatedOne', isolated: true),
            PlanEntryFixture::create('ExampleTest', 'isolatedTwo', isolated: true),
            PlanEntryFixture::create('OtherTest', 'pooled'),
        ], 42);

        [$pooled, $isolated] = new Distributor()->units($plan);

        Expect::value($pooled)
            ->because(
                'pooled entries stay grouped by class and isolated entries each get a unit',
            )
            ->toHaveCount(2);
        Expect::value((string) $pooled[0]->plan->entries[0]->id)
            ->toBe('ExampleTest::pooled');
        Expect::value((string) $pooled[1]->plan->entries[0]->id)
            ->toBe('OtherTest::pooled');
        Expect::value($isolated)
            ->toHaveCount(2);
        Expect::value($isolated[0]->plan->entries)
            ->toHaveCount(1);
        Expect::value((string) $isolated[0]->plan->entries[0]->id)
            ->toBe('ExampleTest::isolatedOne');
        Expect::value($isolated[0]->plan->seed)
            ->toBe(42);
        Expect::value($isolated[1]->plan->entries)
            ->toHaveCount(1);
        Expect::value((string) $isolated[1]->plan->entries[0]->id)
            ->toBe('ExampleTest::isolatedTwo');
        Expect::value($isolated[1]->plan->seed)
            ->toBe(42);
    }

    #[Test]
    public function classUnitsHoldTheUnionOfEveryEntryRequirement(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('ExampleTest', 'one', resources: ['postgres']),
            PlanEntryFixture::create('ExampleTest', 'two', resources: ['redis', 'postgres']),
            PlanEntryFixture::create('OtherTest', 'isolated', resources: ['sandbox'], isolated: true),
        ], 42);

        [$pooled, $isolated] = new Distributor()->units($plan);

        Expect::value($pooled)->because('class units hold the union of every entry requirement')->toHaveCount(1);
        Expect::value($pooled[0]->plan->seed)->because('class units hold the union of every entry requirement')->toBe(42);
        Expect::value($pooled[0]->resources)->because('class units hold the union of every entry requirement')->toBe(['postgres', 'redis']);
        Expect::value($pooled[0]->isolated)->because('class units hold the union of every entry requirement')->toBeFalse();
        Expect::value($isolated)->because('class units hold the union of every entry requirement')->toHaveCount(1);
        Expect::value($isolated[0]->resources)->because('class units hold the union of every entry requirement')->toBe(['sandbox']);
        Expect::value($isolated[0]->isolated)->because('class units hold the union of every entry requirement')->toBeTrue();
    }

    #[Test]
    public function optedInClassEntriesBecomePooledSingletonUnitsInPlanOrder(): void
    {
        $plan = new ExecutionPlan([
            PlanEntryFixture::create('LargeTest', 'rows', 'first', ['database'], allowParallel: true),
            PlanEntryFixture::create('LargeTest', 'rows', 'second', ['queue'], allowParallel: true),
            PlanEntryFixture::create('OtherTest', 'staysTogether'),
            PlanEntryFixture::create('OtherTest', 'alsoStaysTogether'),
        ], 42);

        [$pooled, $isolated] = new Distributor()->units($plan);

        Expect::value(\array_map(
            static fn(SchedulingUnit $unit): array => \array_map(
                static fn(PlanEntry $entry): string => (string) $entry->id,
                $unit->plan->entries,
            ),
            $pooled,
        ))
            ->because('the opt-in MUST split entries without changing their plan order')
            ->toBe([
                ['LargeTest::rows[first]'],
                ['LargeTest::rows[second]'],
                ['OtherTest::staysTogether', 'OtherTest::alsoStaysTogether'],
            ]);
        Expect::value($pooled[0]->plan->seed)->toBe(42);
        Expect::value($pooled[0]->resources)->toBe(['database']);
        Expect::value($pooled[1]->resources)->toBe(['queue']);
        Expect::value($isolated)->toBe([]);
    }
}
