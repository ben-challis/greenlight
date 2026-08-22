<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Execution\ProcessPool\Orchestrator\DispatchKind;
use Greenlight\Execution\ProcessPool\Orchestrator\ResourceLease;
use Greenlight\Execution\ProcessPool\Orchestrator\ResourceScheduler;
use Greenlight\Execution\ProcessPool\Orchestrator\SchedulingUnit;
use Greenlight\Expect\Expect;

/**
 * Creates one-test scheduling units and verifies scheduler assignments.
 *
 * @internal
 */
final class SchedulingFixture
{
    private function __construct() {}

    /**
     * @param non-empty-string $class
     * @param list<non-empty-string> $resources
     */
    public static function unit(string $class, array $resources = [], bool $isolated = false): SchedulingUnit
    {
        return new SchedulingUnit(new ExecutionPlan([
            PlanEntryFixture::create($class, resources: $resources),
        ]), $isolated);
    }

    public static function assignedLease(ResourceScheduler $scheduler, bool $freshWorker = true): ResourceLease
    {
        $decision = $scheduler->dispatch($freshWorker);

        Expect::that($decision->kind)
            ->because('the scheduling fixture requires an assignment')
            ->toBe(DispatchKind::Assign);

        Expect::that($decision->lease)
            ->because(\sprintf('Expected an assignment, got %s.', $decision->kind->name))
            ->toBeInstanceOf(ResourceLease::class);

        return $decision->lease;
    }
}
