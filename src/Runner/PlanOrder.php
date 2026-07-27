<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;

/**
 * Puts priority classes first. Then, it puts classes with known durations in
 * longest-first order. Classes without durations occur last.
 *
 * Entry order within a class never changes.
 *
 * A run with a seed must not supply durations. An order from cached durations
 * would change the order that the seed reproduces.
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
