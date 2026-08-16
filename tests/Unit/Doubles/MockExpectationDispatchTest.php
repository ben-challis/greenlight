<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;

final readonly class MockExpectationDispatchTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function saturatedExpectationYieldsToTheNextMatchingPlan(): void
    {
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(1, 2)->once()->andReturns(3);
            $plan->expects('add')->with(1, 2)->once()->andReturns(4);
        });

        $firstAnswer = $calculator->add(1, 2);
        $secondAnswer = $calculator->add(1, 2);

        Expect::that($firstAnswer)
            ->because('the first expectation answers the first matching call')
            ->toBe(3);
        Expect::that($secondAnswer)
            ->because('a saturated expectation MUST yield to the next matching plan')
            ->toBe(4);
    }
}
