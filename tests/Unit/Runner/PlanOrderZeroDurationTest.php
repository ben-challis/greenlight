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

final readonly class PlanOrderZeroDurationTest
{
    #[Test]
    public function zeroDurationRemainsKnown(): void
    {
        $plan = new ExecutionPlan([
            $this->entry('Acme\\UnknownTest'),
            $this->entry('Acme\\InstantTest'),
        ]);

        $ordered = PlanOrder::schedule($plan, [], [
            'Acme\\InstantTest' => 0.0,
        ]);

        Expect::that($ordered->classes())
            ->because('a zero duration MUST remain known and precede classes without timing data')
            ->toBe([
                'Acme\\InstantTest',
                'Acme\\UnknownTest',
            ]);
    }

    /**
     * @param non-empty-string $class
     */
    private function entry(string $class): PlanEntry
    {
        return new PlanEntry(
            new TestId($class, 'runs'),
            new TestMetadata($class, 'runs'),
        );
    }
}
