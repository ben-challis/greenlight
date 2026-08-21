<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Discovery\ExecutionPlan;

/**
 * Produces one pooled scheduling unit for each test class by default. It
 * produces one-entry units for opted-in classes and isolated tests.
 *
 * @internal
 */
final readonly class Distributor
{
    /**
     * @return array{list<SchedulingUnit>, list<SchedulingUnit>} pooled per-class units in plan order, then isolated single-entry units
     */
    public function units(ExecutionPlan $plan): array
    {
        $pooled = [];
        $isolated = [];

        foreach ($plan->entriesByClass() as $entries) {
            $pooledEntries = [];

            foreach ($entries as $entry) {
                if ($entry->metadata->isolated) {
                    $isolated[] = new SchedulingUnit(new ExecutionPlan([$entry], $plan->seed), true);
                } elseif ($entry->metadata->allowParallel) {
                    $pooled[] = new SchedulingUnit(new ExecutionPlan([$entry], $plan->seed), false);
                } else {
                    $pooledEntries[] = $entry;
                }
            }

            if ($pooledEntries !== []) {
                $pooled[] = new SchedulingUnit(new ExecutionPlan($pooledEntries, $plan->seed), false);
            }
        }

        return [$pooled, $isolated];
    }
}
