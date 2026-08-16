<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Clock;

final readonly class ClassMethodInterceptionTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function classDoublesInterceptConcreteMethods(): void
    {
        $clock = $this->doubles->mock(Clock::class, static function (MockPlan $plan): void {
            $plan->expects('now')->once()->andReturns('fixed-time');
        });

        Expect::that($clock->now())
            ->because('a class double MUST use its configured answer for a concrete method')
            ->toBe('fixed-time');
    }
}
