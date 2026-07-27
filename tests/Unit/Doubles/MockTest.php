<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\Fail;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\UntypedMethod;

final class MockTest
{
    #[Test]
    public function metExpectationsPassVerification(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(1, 2)->once()->andReturns(3);
        });

        Expect::that($calculator->add(1, 2))->because('met expectations pass verification')->toBe(3);

        $doubles->dispose();
    }

    #[Test]
    public function valueReturningCallsRequireAConfiguredAnswer(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(1, 2)->once();
        });

        Expect::that(static fn(): int => $calculator->add(1, 2))
            ->because('value-returning mock calls require a configured answer')
            ->toThrow(
                DoublesError::class,
                message: 'The mock call "' . Calculator::class . '::add()" has no configured answer. '
                    . 'Configure each returned value with andReturns() or andThrows().',
            );

        $doubles->dispose();
    }

    #[Test]
    public function untypedMethodsNeedNoConfiguredAnswer(): void
    {
        $doubles = new Doubles();
        $untyped = $doubles->mock(UntypedMethod::class, static function (MockPlan $plan): void {
            $plan->expects('run')->once();
        });

        Expect::that($untyped->run())
            ->because('untyped mock methods need no configured answer')
            ->toBeNull();

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

            Expect::that($detail->message)->toBe(
                'Calls to Greenlight\Tests\Fixture\Doubles\Calculator::add(): 1 time. '
                . 'The expectation requires exactly 2 times.',
            )
                ->and($detail->expected)->toBe('add(all arguments) exactly 2 times')
                ->and($detail->actual)->toBe('add(1, 2)');

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

        Expect::that(static fn() => $doubles->dispose())->because('an unplanned expectation defaults to at least once')
            ->toThrow(ExpectationFailed::class, '/at least 1 time/');
    }

    /**
     * @param 'times'|'atLeast' $cardinality
     * @param non-empty-string $message
     */
    #[Test]
    #[DataSet('invalidCardinalities')]
    public function invalidCardinalitiesAreRejected(string $cardinality, int $count, string $message): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): Calculator => $doubles->mock(
            Calculator::class,
            static function (MockPlan $plan) use ($cardinality, $count): void {
                $expectation = $plan->expects('add');

                match ($cardinality) {
                    'times' => $expectation->times($count),
                    'atLeast' => $expectation->atLeast($count),
                };
            },
        ))
            ->because('invalid mock cardinalities are rejected')
            ->toThrow(DoublesError::class, message: $message);
    }

    /**
     * @return iterable<string, array{'times'|'atLeast', int, non-empty-string}>
     */
    public static function invalidCardinalities(): iterable
    {
        yield 'negative exact count' => [
            'times',
            -1,
            'times(-1) requires a count of zero or more.',
        ];

        yield 'zero minimum count' => [
            'atLeast',
            0,
            'atLeast(0) requires a count of one or more.',
        ];
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

            Expect::that($detail->message)->toContain('unexpected call')
                ->toContain('add')
                ->and($detail->actual)->toBe('add(4, 5)')
                ->and($detail->expected)->toContain('no calls to add()');

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

        Expect::that($calculator->describe('label'))->because('exact arguments use deep equality')->toBe('matched');

        $doubles->dispose();
    }

    #[Test]
    public function theAnyMatcherAcceptsEveryValueInItsPosition(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(MockPlan::any(), 7)->times(2)->andReturns(7);
        });

        Expect::that($calculator->add(1, 7))->because('any() accepts each value at its position')->toBe(7)
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
            Expect::that($failure->detail()->message)->toContain('unexpected call')
                ->and($failure->detail()->expected)->toContain('never');
        }

        // Greenlight keeps the call failure. Thus, verification reports it
        // again.
        Expect::that(static fn() => $doubles->dispose())->because('never() causes each call to fail immediately')
            ->toThrow(ExpectationFailed::class, '/unexpected call/');
    }

    #[Test]
    public function callsBeyondTheAllowedCountFailImmediately(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->once()->andReturns(1);
        });

        $calculator->add(1, 1);

        Expect::that(static fn(): int => $calculator->add(1, 1))->because('calls beyond the allowed count fail immediately')
            ->toThrow(ExpectationFailed::class, '/unexpected call/');
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

        Expect::that(static fn(): int => $calculator->add(1, 2))->because('and() throws raises the configured throwable')
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

        Expect::that(static fn() => $doubles->dispose())->because('a swallowed unexpected call still fails verification')
            ->toThrow(ExpectationFailed::class, '/unexpected call/');
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
