<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery\Plan;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanOrder;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PlanEntryFixture;

final class PlanOrderTest
{
    #[Test]
    public function failedFirstThenLongestThenUnknownInDiscoveredOrder(): void
    {
        $plan = $this->plan(['Acme\A', 'Acme\B', 'Acme\C', 'Acme\D', 'Acme\E']);

        $ordered = PlanOrder::schedule(
            $plan,
            priorityClasses: ['Acme\D'],
            classSeconds: ['Acme\B' => 0.5, 'Acme\E' => 2.0],
        );

        Expect::that($this->classes($ordered))->because('failed first then longest then unknown in discovered order')->toBe(['Acme\D', 'Acme\E', 'Acme\B', 'Acme\A', 'Acme\C']);
    }

    #[Test]
    public function priorityWinsOverRecordedDuration(): void
    {
        $plan = $this->plan(['Acme\A', 'Acme\B']);

        $ordered = PlanOrder::schedule($plan, ['Acme\A'], ['Acme\A' => 0.1, 'Acme\B' => 9.0]);

        Expect::that($this->classes($ordered))->because('priority wins over recorded duration')->toBe(['Acme\A', 'Acme\B']);
    }

    #[Test]
    public function duplicatePriorityClassesDoNotDuplicateTests(): void
    {
        $plan = $this->plan(['Acme\A', 'Acme\B']);

        $ordered = PlanOrder::schedule($plan, ['Acme\B', 'Acme\B', 'Acme\Missing', 'Acme\B'], []);

        Expect::that($this->classes($ordered))
            ->because('priority inputs MUST preserve each planned test exactly once')
            ->toBe(['Acme\B', 'Acme\A']);
        Expect::that($ordered->count())
            ->toBe(2);
    }

    #[Test]
    public function withNothingRecordedThePlanIsUntouched(): void
    {
        $plan = $this->plan(['Acme\B', 'Acme\A']);

        Expect::that(PlanOrder::schedule($plan, [], []))->because('with nothing recorded the plan is untouched')->toBe($plan);
    }

    /**
     * @param list<non-empty-string> $classes
     */
    private function plan(array $classes): ExecutionPlan
    {
        $entries = [];

        foreach ($classes as $class) {
            $entries[] = PlanEntryFixture::create($class, 'probe');
        }

        return new ExecutionPlan($entries);
    }

    /**
     * @return list<string>
     */
    private function classes(ExecutionPlan $plan): array
    {
        $seen = [];

        foreach ($plan->entries as $entry) {
            if (!\in_array($entry->id->class, $seen, true)) {
                $seen[] = $entry->id->class;
            }
        }

        return $seen;
    }
}
