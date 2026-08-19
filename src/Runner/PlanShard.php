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

        /** @var array<non-empty-string, int<0, max>> $shards */
        $shards = [];

        /** @var list<PlanEntry> $entries */
        $entries = \array_values(\array_filter(
            $plan->entries,
            static function (PlanEntry $entry) use (&$shards, $count, $index): bool {
                $class = $entry->id->class;
                $shard = $shards[$class] ??= self::shardIndex($class, $count);

                return $shard === $index - 1;
            },
        ));

        return new ExecutionPlan($entries, $plan->seed);
    }

    /**
     * Returns the unsigned CRC32 remainder without exceeding the PHP integer
     * range. crc32() returns negative values for high-bit checksums on 32-bit
     * PHP.
     *
     * @param non-empty-string $class
     * @param positive-int $count
     *
     * @return int<0, max>
     */
    private static function shardIndex(string $class, int $count): int
    {
        $remainder = 0;
        $checksum = \hash('crc32b', $class);

        for ($offset = 0; $offset < 8; ++$offset) {
            for ($shift = 0; $shift < 4; ++$shift) {
                $remainder = self::addModulo($remainder, $remainder, $count);
            }

            $digit = \max(0, \intval($checksum[$offset], 16));
            $remainder = self::addModulo($remainder, $digit % $count, $count);
        }

        return $remainder;
    }

    /**
     * @param int<0, max> $left
     * @param int<0, max> $right
     * @param positive-int $modulus
     *
     * @return int<0, max>
     */
    private static function addModulo(int $left, int $right, int $modulus): int
    {
        $distance = $modulus - $right;

        if ($left >= $distance) {
            return \max(0, $left - $distance);
        }

        return \max(0, $left + $right);
    }
}
