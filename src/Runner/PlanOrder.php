<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;

/**
 * Orders a plan's classes for scheduling: previously failed classes first
 * (fast feedback beats optimal packing), then classes with recorded
 * durations longest first so the big rocks are placed while every worker is
 * still hungry, then classes the cache does not know, in their discovered
 * order. Entry order within a class never changes. Seeded runs must not
 * pass durations: a randomized order silently rewritten by a hidden cache
 * is a debugging trap.
 *
 * @internal
 */
final class PlanOrder
{
    private function __construct() {}

    /**
     * @param list<non-empty-string> $priorityClasses
     * @param array<string, float> $classSeconds
     */
    public static function schedule(ExecutionPlan $plan, array $priorityClasses, array $classSeconds): ExecutionPlan
    {
        if ($priorityClasses === [] && $classSeconds === []) {
            return $plan;
        }

        $byClass = [];

        foreach ($plan->entries as $entry) {
            $byClass[$entry->id->class][] = $entry;
        }

        $order = [];

        foreach ($priorityClasses as $class) {
            if (isset($byClass[$class])) {
                $order[] = $class;
            }
        }

        $known = [];
        $unknown = [];

        foreach (\array_keys($byClass) as $class) {
            if (\in_array($class, $order, true)) {
                continue;
            }

            if (isset($classSeconds[$class])) {
                $known[$class] = $classSeconds[$class];
            } else {
                $unknown[] = $class;
            }
        }

        \arsort($known);
        $order = [...$order, ...\array_keys($known), ...$unknown];

        /** @var list<PlanEntry> $entries */
        $entries = [];

        foreach ($order as $class) {
            foreach ($byClass[$class] as $entry) {
                $entries[] = $entry;
            }
        }

        return new ExecutionPlan($entries, $plan->seed);
    }
}
