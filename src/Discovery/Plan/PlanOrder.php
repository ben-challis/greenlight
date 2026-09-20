<?php

declare(strict_types=1);

namespace Greenlight\Discovery\Plan;

use Greenlight\Plugin\Prioritized;
use Greenlight\Plugin\TestPlan;
use Greenlight\Plugin\TestPlanTransformer;
use Greenlight\Test\TestId;

/**
 * Applies failed-first and longest-first class ordering.
 *
 * @internal
 */
final readonly class PlanOrder implements Prioritized, TestPlanTransformer
{
    /**
     * @param list<non-empty-string> $priorityClasses
     * @param array<string, float> $classSeconds
     */
    public function __construct(
        private array $priorityClasses,
        private array $classSeconds,
    ) {}

    #[\Override]
    public function priority(): int
    {
        return \PHP_INT_MIN;
    }

    #[\Override]
    public function transformTestPlan(TestPlan $plan): TestPlan
    {
        if ($this->priorityClasses === [] && $this->classSeconds === []) {
            return $plan;
        }

        $byClass = [];

        foreach ($plan->tests as $test) {
            $byClass[$test->class][] = $test;
        }

        $order = [];
        $prioritized = [];

        foreach ($this->priorityClasses as $class) {
            if (!isset($byClass[$class]) || isset($prioritized[$class])) {
                continue;
            }

            $order[] = $class;
            $prioritized[$class] = true;
        }

        $known = [];
        $unknown = [];

        foreach (\array_keys($byClass) as $class) {
            if (isset($prioritized[$class])) {
                continue;
            }

            if (isset($this->classSeconds[$class])) {
                $known[$class] = $this->classSeconds[$class];
            } else {
                $unknown[] = $class;
            }
        }

        \arsort($known);
        $order = [...$order, ...\array_keys($known), ...$unknown];
        $tests = [];

        foreach ($order as $class) {
            foreach ($byClass[$class] as $test) {
                $tests[] = $test;
            }
        }

        return $plan->withTests($tests);
    }

    /**
     * @param list<non-empty-string> $priorityClasses
     * @param array<string, float> $classSeconds
     */
    public static function schedule(
        ExecutionPlan $plan,
        array $priorityClasses,
        array $classSeconds,
    ): ExecutionPlan {
        $publicPlan = TestPlan::create(\array_map(
            static fn(PlanEntry $entry) => $entry->id,
            $plan->entries,
        ));
        $replacement = new self($priorityClasses, $classSeconds)->transformTestPlan($publicPlan);

        if ($replacement === $publicPlan) {
            return $plan;
        }

        $entries = [];

        foreach ($plan->entries as $entry) {
            $entries[(string) $entry->id] = $entry;
        }

        return new ExecutionPlan(\array_map(
            static fn(TestId $test): PlanEntry => $entries[(string) $test],
            $replacement->tests,
        ), $plan->seed);
    }
}
