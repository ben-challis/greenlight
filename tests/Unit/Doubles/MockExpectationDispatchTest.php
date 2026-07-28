<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;

final class MockExpectationDispatchTest
{
    #[Test]
    public function saturatedExpectationYieldsToTheNextMatchingPlan(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(1, 2)->once()->andReturns(3);
            $plan->expects('add')->with(1, 2)->once()->andReturns(4);
        });

        $answers = [
            $calculator->add(1, 2),
            $calculator->add(1, 2),
        ];
        $doubles->dispose();

        Expect::that($answers)
            ->because('a saturated expectation MUST yield to the next matching plan')
            ->toBe([3, 4]);
    }
}
