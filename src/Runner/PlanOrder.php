<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;

/**
 * Orders a plan's classes for scheduling.
 *
 * schedule() places previously failed classes first for fast feedback.
 * Classes with recorded durations follow, longest first, so the longest
 * classes are assigned while workers are still free. Classes without a
 * recorded duration come last, in their discovered order.
 *
 * Entry order within a class never changes.
 *
 * Seeded runs must not pass durations; reordering by cached durations would
 * change the order a seed reproduces.
 *
 * @internal
 */
final class PlanOrder
{
    #[CoverageIgnore]
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
