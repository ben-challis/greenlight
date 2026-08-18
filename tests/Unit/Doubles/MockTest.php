<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\UntypedMethod;

final class MockTest
{
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


        Expect::that(static fn() => $doubles->dispose())
            ->because('Doubles::dispose() MUST fail for the unmet add() expectation')
            ->toThrow(static function (ExpectationFailed $failure): void {
                $detail = $failure->detail();

                Expect::that($detail->message)->toBe(
                    'Calls to Greenlight\Tests\Fixture\Doubles\Calculator::add(): 1 time. '
                    . 'The expectation requires exactly 2 times.',
                );
                Expect::that($detail->expected)->toBe('add(all arguments) exactly 2 times');
                Expect::that($detail->actual)->toBe('add(1, 2)');
            });
    }

    #[Test]
    public function disposalAggregatesEveryUnmetExpectationInPlanOrder(): void
    {
        $doubles = new Doubles();
        $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->once()->andReturns(0);
            $plan->expects('describe')->once()->andReturns('');
        });

        Expect::that(static fn() => $doubles->dispose())
            ->because('disposal MUST report every unmet expectation in plan order')
            ->toThrow(static function (ExpectationFailed $failure): void {
                Expect::that($failure->getMessage())->toBe(
                    "2 expectations failed:\n"
                    . '1) Calls to ' . Calculator::class . '::add(): 0 times. '
                    . "The expectation requires exactly 1 time.\n"
                    . '2) Calls to ' . Calculator::class . '::describe(): 0 times. '
                    . 'The expectation requires exactly 1 time.',
                );

                Expect::that($failure->details)->toEqual([
                    new FailureDetail(
                        'Calls to ' . Calculator::class . '::add(): 0 times. '
                            . 'The expectation requires exactly 1 time.',
                        'add(all arguments) exactly 1 time',
                        'never called',
                    ),
                    new FailureDetail(
                        'Calls to ' . Calculator::class . '::describe(): 0 times. '
                            . 'The expectation requires exactly 1 time.',
                        'describe(all arguments) exactly 1 time',
                        'never called',
                    ),
                ]);
            });
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


        Expect::that(static fn(): int => $calculator->add(4, 5))
            ->because('the unplanned add(4, 5) call MUST fail immediately')
            ->toThrow(static function (ExpectationFailed $failure): void {
                $detail = $failure->detail();

                Expect::that($detail->message)->toContain('unexpected call')
                    ->toContain('add');
                Expect::that($detail->actual)->toBe('add(4, 5)');
                Expect::that($detail->expected)->toContain('no calls to add()');
            });
    }

    #[Test]
    public function anArgumentMismatchFailsImmediatelyWithADiff(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('describe')->with('expected label')->once()->andReturns('ok');
        });


        Expect::that(static fn(): string => $calculator->describe('other label'))
            ->because("describe('other label') MUST fail its exact argument matcher")
            ->toThrow(static function (ExpectationFailed $failure): void {
                $detail = $failure->detail();

                Expect::that($detail->expected)->toContain("describe('expected label') exactly 1 time");
                Expect::that($detail->actual)->toBe("describe('other label')");
            });
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

        Expect::that($calculator->add(1, 7))->because('any() accepts each value at its position')->toBe(7);
        Expect::that($calculator->add(999, 7))->toBe(7);

        $doubles->dispose();
    }

    #[Test]
    public function neverMeansAnyCallFailsImmediately(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->never();
        });


        Expect::that(static fn(): int => $calculator->add(1, 1))
            ->because('add(1, 1) MUST fail because add() was configured with never()')
            ->toThrow(static function (ExpectationFailed $failure): void {
                Expect::that($failure->detail()->message)->toContain('unexpected call');
                Expect::that($failure->detail()->expected)->toContain('never');
            });

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
        $throwable = new \RuntimeException('gateway down');
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan) use ($throwable): void {
            $plan->expects('add')->once()->andThrows($throwable);
        });

        Expect::that(static fn(): int => $calculator->add(1, 2))->because('andThrows() raises the configured throwable')
            ->toThrow($throwable);

        $doubles->dispose();
    }

    #[Test]
    public function aSwallowedUnexpectedCallStillFailsVerification(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class);

        Expect::that(static fn(): int => $calculator->add(1, 2))
            ->because('the unexpected call MUST fail before verification')
            ->toThrow(ExpectationFailed::class);

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

        Expect::that(static fn() => $doubles->dispose())
            ->because('verification MUST fail before it drops state')
            ->toThrow(ExpectationFailed::class);

        $doubles->dispose();
    }
}
