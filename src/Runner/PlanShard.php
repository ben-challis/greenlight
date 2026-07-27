<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;

/**
 * Selects a test-class shard with a stable hash.
 *
 * select() lets each CI computer select its shard independently. All shards
 * together contain the complete plan with no duplicate entries. The seed and
 * filters do not change this property because shard selection occurs after
 * filter application.
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
