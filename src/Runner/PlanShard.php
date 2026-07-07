<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;

/**
 * Selects one of m equal slices of a plan by stable class hash, so CI
 * machines can split a suite with zero coordination: the union of all
 * shards is exactly the full plan and shards are disjoint, whatever the
 * seed or filters, because selection happens on the already-filtered plan.
 * Whole classes relocate, never single methods, since class-level hooks and
 * fixtures make the class the smallest unit that moves between machines
 * safely.
 *
 * @internal
 */
final class PlanShard
{
    private function __construct() {}

    /**
     * @param positive-int $index 1-based shard number
     * @param positive-int $count total shards
     */
    public static function select(ExecutionPlan $plan, int $index, int $count): ExecutionPlan
    {
        if ($count === 1) {
            return $plan;
        }

        /** @var list<PlanEntry> $entries */
        $entries = \array_values(\array_filter(
            $plan->entries,
            static fn(PlanEntry $entry): bool => \crc32($entry->id->class) % $count === $index - 1,
        ));

        return new ExecutionPlan($entries, $plan->seed);
    }
}
