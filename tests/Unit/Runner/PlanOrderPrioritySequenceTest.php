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

final class PlanOrderPrioritySequenceTest
{
    #[Test]
    public function distinctPriorityClassesRetainTheCallerSequence(): void
    {
        $plan = new ExecutionPlan([
            $this->entry('Acme\\AlphaTest'),
            $this->entry('Acme\\BetaTest'),
            $this->entry('Acme\\GammaTest'),
            $this->entry('Acme\\DeltaTest'),
        ], seed: 4242);

        $ordered = PlanOrder::schedule(
            $plan,
            priorityClasses: ['Acme\\GammaTest', 'Acme\\AlphaTest'],
            classSeconds: [
                'Acme\\BetaTest' => 1.0,
                'Acme\\DeltaTest' => 2.0,
            ],
        );

        Expect::that([$ordered->classes(), $ordered->seed])
            ->because('priority classes MUST retain the caller sequence before duration ordering')
            ->toBe([
                [
                    'Acme\\GammaTest',
                    'Acme\\AlphaTest',
                    'Acme\\DeltaTest',
                    'Acme\\BetaTest',
                ],
                4242,
            ]);
    }

    /**
     * @param non-empty-string $class
     */
    private function entry(string $class): PlanEntry
    {
        return new PlanEntry(
            new TestId($class, 'probe'),
            new TestMetadata($class, 'probe'),
        );
    }
}
