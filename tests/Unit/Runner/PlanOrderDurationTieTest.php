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

final readonly class PlanOrderDurationTieTest
{
    #[Test]
    public function equalDurationsPreserveDiscoveryOrder(): void
    {
        $plan = new ExecutionPlan([
            $this->entry('Acme\\GammaTest'),
            $this->entry('Acme\\AlphaTest'),
            $this->entry('Acme\\BetaTest'),
        ]);

        $ordered = PlanOrder::schedule($plan, [], [
            'Acme\\BetaTest' => 1.0,
            'Acme\\AlphaTest' => 1.0,
            'Acme\\GammaTest' => 1.0,
        ]);

        Expect::that($ordered->classes())
            ->because('equal recorded durations MUST preserve discovery order')
            ->toBe([
                'Acme\\GammaTest',
                'Acme\\AlphaTest',
                'Acme\\BetaTest',
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
