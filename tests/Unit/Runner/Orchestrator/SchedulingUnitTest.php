<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\SchedulingUnit;

final class SchedulingUnitTest
{
    #[Test]
    public function itCannotBeEmpty(): void
    {
        Expect::that(static fn(): SchedulingUnit => new SchedulingUnit(new ExecutionPlan([]), false))->because('a scheduling unit cannot be empty')
            ->toThrow(\InvalidArgumentException::class, '/cannot be empty/');
    }

    #[Test]
    public function resourcesAreUniqueAcrossEveryEntryInTheClass(): void
    {
        $unit = new SchedulingUnit(new ExecutionPlan([
            $this->entry('first', ['database', 'cache']),
            $this->entry('second', ['database']),
        ]), false);

        Expect::that($unit->resources)
            ->because('one class MUST acquire each required resource once')
            ->toBe(['database', 'cache']);
    }

    /**
     * @param non-empty-string $method
     * @param list<non-empty-string> $resources
     */
    private function entry(string $method, array $resources): PlanEntry
    {
        $class = 'ExampleTest';

        return new PlanEntry(
            new TestId($class, $method),
            new TestMetadata($class, $method, resources: $resources),
        );
    }
}
