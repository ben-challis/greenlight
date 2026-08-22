<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Execution\ProcessPool\Orchestrator\SchedulingUnit;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PlanEntryFixture;

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
            PlanEntryFixture::create('ExampleTest', 'first', resources: ['database', 'cache']),
            PlanEntryFixture::create('ExampleTest', 'second', resources: ['database']),
        ]), false);

        Expect::that($unit->resources)
            ->because('one class MUST acquire each required resource once')
            ->toBe(['database', 'cache']);
    }
}
