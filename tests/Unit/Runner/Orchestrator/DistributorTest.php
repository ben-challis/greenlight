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

final class DistributorTest
{
    #[Test]
    public function isolatedEntriesBecomeSingletonUnitsInPlanOrder(): void
    {
        $plan = new ExecutionPlan([
            $this->entry('ExampleTest', 'pooled', []),
            $this->entry('ExampleTest', 'isolatedOne', [], isolated: true),
            $this->entry('ExampleTest', 'isolatedTwo', [], isolated: true),
            $this->entry('OtherTest', 'pooled', []),
        ], 42);

        [$pooled, $isolated] = new Distributor()->units($plan);

        Expect::that($pooled)
            ->because(
                'pooled entries stay grouped by class and isolated entries each get a unit',
            )
            ->toHaveCount(2);
        Expect::that((string) $pooled[0]->plan->entries[0]->id)
            ->toBe('ExampleTest::pooled');
        Expect::that((string) $pooled[1]->plan->entries[0]->id)
            ->toBe('OtherTest::pooled');
        Expect::that($isolated)
            ->toHaveCount(2);
        Expect::that($isolated[0]->plan->entries)
            ->toHaveCount(1);
        Expect::that((string) $isolated[0]->plan->entries[0]->id)
            ->toBe('ExampleTest::isolatedOne');
        Expect::that($isolated[0]->plan->seed)
            ->toBe(42);
        Expect::that($isolated[1]->plan->entries)
            ->toHaveCount(1);
        Expect::that((string) $isolated[1]->plan->entries[0]->id)
            ->toBe('ExampleTest::isolatedTwo');
        Expect::that($isolated[1]->plan->seed)
            ->toBe(42);
    }

    #[Test]
    public function classUnitsHoldTheUnionOfEveryEntryRequirement(): void
    {
        $plan = new ExecutionPlan([
            $this->entry('ExampleTest', 'one', ['postgres']),
            $this->entry('ExampleTest', 'two', ['redis', 'postgres']),
            $this->entry('OtherTest', 'isolated', ['sandbox'], isolated: true),
        ], 42);

        [$pooled, $isolated] = new Distributor()->units($plan);

        Expect::that($pooled)->because('class units hold the union of every entry requirement')->toHaveCount(1);
        Expect::that($pooled[0]->plan->seed)->because('class units hold the union of every entry requirement')->toBe(42);
        Expect::that($pooled[0]->resources)->because('class units hold the union of every entry requirement')->toBe(['postgres', 'redis']);
        Expect::that($pooled[0]->isolated)->because('class units hold the union of every entry requirement')->toBeFalse();
        Expect::that($isolated)->because('class units hold the union of every entry requirement')->toHaveCount(1);
        Expect::that($isolated[0]->resources)->because('class units hold the union of every entry requirement')->toBe(['sandbox']);
        Expect::that($isolated[0]->isolated)->because('class units hold the union of every entry requirement')->toBeTrue();
    }

    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     * @param list<non-empty-string> $resources
     */
    private function entry(string $class, string $method, array $resources, bool $isolated = false): PlanEntry
    {
        return new PlanEntry(
            new TestId($class, $method),
            new TestMetadata($class, $method, isolated: $isolated, resources: $resources),
        );
    }
}
