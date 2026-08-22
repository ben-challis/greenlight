<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Tests\Fixture\Doubles\Calculator;

final class DoublesDisposalIsolationTest
{
    #[Test]
    #[DataSet('disposalOutcomes')]
    public function disposalForgetsDoublesAfterVerification(bool $verificationFails): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(1, 2)->once()->andReturns(3);
        });

        if ($verificationFails) {
            Expect::that(static fn() => $doubles->dispose())
                ->because('the selected disposal outcome MUST fail verification')
                ->toThrow(ExpectationFailed::class);
        } else {
            $calculator->add(1, 2);
            $doubles->dispose();
        }

        Expect::that(static fn(): array => $doubles->callsTo($calculator, 'add'))
            ->because('disposal MUST revoke access to recordings from the closed verification scope')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'This Doubles factory did not create the ' . $calculator::class . ' instance.',
            );
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function disposalOutcomes(): iterable
    {
        yield 'successful verification' => [false];
        yield 'failed verification' => [true];
    }
}
