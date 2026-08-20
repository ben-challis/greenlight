<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\DispatchKind;
use Greenlight\Runner\Orchestrator\ResourceLease;
use Greenlight\Runner\Orchestrator\ResourceScheduler;
use Greenlight\Runner\Orchestrator\SchedulingUnit;

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
        $id = new TestId($class, 'runs');

        return new SchedulingUnit(new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($class, 'runs', resources: $resources)),
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
