<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\PlanOrder;

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

        Expect::that($this->classes($ordered))->toBe(['Acme\D', 'Acme\E', 'Acme\B', 'Acme\A', 'Acme\C']);
    }

    #[Test]
    public function priorityWinsOverRecordedDuration(): void
    {
        $plan = $this->plan(['Acme\A', 'Acme\B']);

        $ordered = PlanOrder::schedule($plan, ['Acme\A'], ['Acme\A' => 0.1, 'Acme\B' => 9.0]);

        Expect::that($this->classes($ordered))->toBe(['Acme\A', 'Acme\B']);
    }

    #[Test]
    public function withNothingRecordedThePlanIsUntouched(): void
    {
        $plan = $this->plan(['Acme\B', 'Acme\A']);

        Expect::that(PlanOrder::schedule($plan, [], []))->toBe($plan);
    }

    /**
     * @param list<non-empty-string> $classes
     */
    private function plan(array $classes): ExecutionPlan
    {
        $entries = [];

        foreach ($classes as $class) {
            $entries[] = new PlanEntry(new TestId($class, 'probe'), new TestMetadata($class, 'probe'));
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
