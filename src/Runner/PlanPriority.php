<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;

/**
 * Reorders a plan so the given classes run first, preserving relative order
 * within both groups. Watch mode uses this for failed-first re-runs.
 *
 * @internal
 */
final class PlanPriority
{
    private function __construct() {}

    /**
     * @param list<non-empty-string> $priorityClasses
     */
    public static function prioritize(ExecutionPlan $plan, array $priorityClasses): ExecutionPlan
    {
        if ($priorityClasses === []) {
            return $plan;
        }

        $first = [];
        $rest = [];

        foreach ($plan->entries as $entry) {
            if (\in_array($entry->metadata->class, $priorityClasses, true)) {
                $first[] = $entry;
            } else {
                $rest[] = $entry;
            }
        }

        /** @var list<PlanEntry> $reordered */
        $reordered = [...$first, ...$rest];

        return new ExecutionPlan($reordered, $plan->seed);
    }
}
