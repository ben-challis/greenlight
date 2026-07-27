<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\Fail;
use Greenlight\Tests\Fixture\Doubles\Calculator;

final class MockTest
{
    #[Test]
    public function metExpectationsPassVerification(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(1, 2)->once()->andReturns(3);
        });

        Expect::that($calculator->add(1, 2))->toBe(3);

        $doubles->dispose();
    }

    #[Test]
    public function unmetCallCountFailsVerificationWithADetail(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->times(2)->andReturns(0);
        });

        $calculator->add(1, 2);


        try {
            $doubles->dispose();
        } catch (ExpectationFailed $failure) {
            $detail = $failure->detail();

            Expect::that($detail->message)->toContain('add')
                ->toContain('exactly 2 times')
                ->toContain('1 time')
                ->and($detail->expected)->toContain('exactly 2 times')
                ->and($detail->actual)->toContain('add(1, 2)');

            return;
        }

        Fail::because('Expected Doubles::dispose() to fail for the unmet add() expectation.');
    }

    #[Test]
    public function anUnplannedExpectationDefaultsToAtLeastOnce(): void
    {
        $doubles = new Doubles();
        $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->andReturns(0);
        });

        Expect::that(static fn() => $doubles->dispose())
            ->toThrow(ExpectationFailed::class, '/at least 1 time/');
    }

    #[Test]
    public function anUnexpectedCallFailsImmediatelyWithRenderedArguments(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class);


        try {
            $calculator->add(4, 5);
        } catch (ExpectationFailed $failure) {
            $detail = $failure->detail();

            Expect::that($detail->message)->toContain('Unexpected call')
                ->toContain('add')
                ->and($detail->actual)->toBe('add(4, 5)')
                ->and($detail->expected)->toContain('no call to add() was expected');

            return;
        }

        Fail::because('Expected the unplanned add(4, 5) call to fail immediately.');
    }

    #[Test]
    public function anArgumentMismatchFailsImmediatelyWithADiff(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('describe')->with('expected label')->once()->andReturns('ok');
        });


        try {
            $calculator->describe('other label');
        } catch (ExpectationFailed $failure) {
            $detail = $failure->detail();

            Expect::that($detail->expected)->toContain("describe('expected label') exactly 1 time")
                ->and($detail->actual)->toBe("describe('other label')");

            return;
        }

        Fail::because("Expected describe('other label') to fail its exact argument matcher.");
    }

    #[Test]
    public function exactArgumentsUseDeepEquality(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('describe')->with('label')->once()->andReturns('matched');
        });

        Expect::that($calculator->describe('label'))->toBe('matched');

        $doubles->dispose();
    }

    #[Test]
    public function theAnyMatcherAcceptsEveryValueInItsPosition(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(MockPlan::any(), 7)->times(2)->andReturns(7);
        });

        Expect::that($calculator->add(1, 7))->toBe(7)
            ->and($calculator->add(999, 7))->toBe(7);

        $doubles->dispose();
    }

    #[Test]
    public function neverMeansAnyCallFailsImmediately(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->never();
        });


        try {
            $calculator->add(1, 1);

            Fail::because('Expected add(1, 1) to fail because add() was configured with never().');
        } catch (ExpectationFailed $failure) {
            Expect::that($failure->detail()->message)->toContain('Unexpected call')
                ->and($failure->detail()->expected)->toContain('never');
        }

        // Greenlight keeps the call failure. Thus, verification reports it
        // again.
        Expect::that(static fn() => $doubles->dispose())
            ->toThrow(ExpectationFailed::class, '/Unexpected call/');
    }

    #[Test]
    public function callsBeyondTheAllowedCountFailImmediately(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->once()->andReturns(1);
        });

        $calculator->add(1, 1);

        Expect::that(static fn(): int => $calculator->add(1, 1))
            ->toThrow(ExpectationFailed::class, '/Unexpected call/');
    }

    #[Test]
    public function atLeastIsSatisfiedByMoreCalls(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->atLeast(2)->andReturns(0);
        });

        $calculator->add(1, 1);
        $calculator->add(2, 2);
        $calculator->add(3, 3);

        $doubles->dispose();
    }

    #[Test]
    public function andThrowsRaisesTheConfiguredThrowable(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->once()->andThrows(new \RuntimeException('gateway down'));
        });

        Expect::that(static fn(): int => $calculator->add(1, 2))
            ->toThrow(\RuntimeException::class, '/gateway down/');

        $doubles->dispose();
    }

    #[Test]
    public function aSwallowedUnexpectedCallStillFailsVerification(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class);

        try {
            $calculator->add(1, 2);
        } catch (ExpectationFailed) {
            // Ignore this exception intentionally. Verification must still
            // fail the test.
        }

        Expect::that(static fn() => $doubles->dispose())
            ->toThrow(ExpectationFailed::class, '/Unexpected call/');
    }

    #[Test]
    public function verificationDropsStateSoASecondDisposeIsClean(): void
    {
        $doubles = new Doubles();
        $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->once();
        });

        try {
            $doubles->dispose();
        } catch (ExpectationFailed) {
            // Expected because code did not call add().
        }

        $doubles->dispose();
    }
}
