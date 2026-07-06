<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;

/**
 * Deterministic assignment of plan entries to worker slices: whole classes
 * placed by stable hash of the class name, so a given seed and code state
 * always yield the same placement. Isolated entries each get a slice of
 * their own, executed by a dedicated fresh worker.
 *
 * @internal
 */
final readonly class Distributor
{
    /**
     * @param positive-int $workerCount
     *
     * @return non-empty-list<ExecutionPlan> at most workerCount pooled slices, plus one slice per isolated entry
     */
    public function slices(ExecutionPlan $plan, int $workerCount): array
    {
        /** @var array<int, list<PlanEntry>> $buckets */
        $buckets = [];
        $isolated = [];

        foreach ($plan->entriesByClass() as $class => $entries) {
            $pooled = [];

            foreach ($entries as $entry) {
                if ($entry->metadata->isolated) {
                    $isolated[] = $entry;
                } else {
                    $pooled[] = $entry;
                }
            }

            if ($pooled !== []) {
                $bucket = \crc32($class) % $workerCount;
                $buckets[$bucket] = [...$buckets[$bucket] ?? [], ...$pooled];
            }
        }

        \ksort($buckets);
        $slices = [];

        foreach ($buckets as $entries) {
            $slices[] = new ExecutionPlan($entries, $plan->seed);
        }

        foreach ($isolated as $entry) {
            $slices[] = new ExecutionPlan([$entry], $plan->seed);
        }

        if ($slices === []) {
            $slices[] = new ExecutionPlan([], $plan->seed);
        }

        return $slices;
    }
}
