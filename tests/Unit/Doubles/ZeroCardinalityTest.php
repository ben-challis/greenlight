<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;

final class ZeroCardinalityTest
{
    #[Test]
    public function anUncalledZeroCardinalityExpectationPassesVerification(): void
    {
        Expect::that(static function (): void {
            $doubles = new Doubles();
            $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
                $plan->expects('add')->times(0);
            });

            $doubles->dispose();
        })
            ->because('times(0) MUST permit an uncalled expectation')
            ->not()
            ->toThrow(\Throwable::class);
    }
}
