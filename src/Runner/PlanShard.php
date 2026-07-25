<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;

/**
 * Selects a class-based shard by stable hash.
 *
 * select() lets each CI machine pick its shard independently: the union of
 * all shards is exactly the full plan and shards are disjoint, whatever the
 * seed or filters, because selection happens on the already-filtered plan.
 *
 * Classes never split across shards.
 *
 * @internal
 */
final class PlanShard
{
    #[CoverageIgnore]
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
