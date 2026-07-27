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
