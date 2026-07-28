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
     * @param int $index 1-based shard number
     * @param int $count total shards
     *
     * @throws \InvalidArgumentException
     */
    public static function select(ExecutionPlan $plan, int $index, int $count): ExecutionPlan
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('The shard count must be at least 1.');
        }

        if ($index < 1 || $index > $count) {
            throw new \InvalidArgumentException(\sprintf(
                'The shard index must be between 1 and %d.',
                $count,
            ));
        }

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
