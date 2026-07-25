<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Discovery\ExecutionPlan;

/**
 * Produces pooled units per class and single-entry units for isolated tests.
 *
 * @internal
 */
final readonly class Distributor
{
    /**
     * @return array{list<ExecutionPlan>, list<ExecutionPlan>} pooled per-class units in plan order, then isolated single-entry units
     */
    public function units(ExecutionPlan $plan): array
    {
        $pooled = [];
        $isolated = [];

        foreach ($plan->entriesByClass() as $entries) {
            $pooledEntries = [];

            foreach ($entries as $entry) {
                if ($entry->metadata->isolated) {
                    $isolated[] = new ExecutionPlan([$entry], $plan->seed);
                } else {
                    $pooledEntries[] = $entry;
                }
            }

            if ($pooledEntries !== []) {
                $pooled[] = new ExecutionPlan($pooledEntries, $plan->seed);
            }
        }

        return [$pooled, $isolated];
    }
}
