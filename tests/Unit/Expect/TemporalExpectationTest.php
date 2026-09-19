<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Expect\PendingEventually;
use Greenlight\Test\Cleanup;
use Greenlight\Test\ExpectationCounter;
use Greenlight\Tests\Fixture\Expect\FakeClock;
use Greenlight\Tests\Fixture\Expect\PositiveNumbersExtension;
use Greenlight\Tests\Fixture\Expect\TransientProbeFailure;

use function Greenlight\expect;

final readonly class TemporalExpectationTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function eventuallyStopsAtTheFirstMatchingObservation(): void
    {
        $clock = new FakeClock();
        $values = ['pending', 'pending', 'ready'];
        $calls = 0;

        ExpectationRuntime::withClock($clock, static function () use (&$calls, $values): void {
            expect()->calling(static function () use (&$calls, $values): string {
                return $values[$calls++];
            })->returnValue()->eventually()
                ->pollEvery(0.010)
                ->within(0.100)
                ->toEqual('ready')
                ->toBe('ready');
        });

        expect($calls)->because('eventually() stops at the first matching observation')->toBe(3);
        expect($clock->sleeps)->toEqual([0.010, 0.010]);
    }

    #[Test]
    public function temporalNativeMatchersPreserveNamedAndVariadicArguments(): void
    {
        $clock = new FakeClock();

        ExpectationRuntime::withClock($clock, static function (): void {
            expect()->calling(static fn(): float => 10.1)->returnValue()->eventually()
                ->within(0.100)
                ->toBeWithin(delta: 0.2, of: 10.0);
            expect()->calling(static fn(): string => 'greenlight')->returnValue()->consistently()
                ->for(0.001)
                ->toBeOneOf(other: 'red', expected: 'greenlight');
        });

        expect($clock->sleeps)
            ->because('native matcher dispatch MUST preserve named and variadic arguments')
            ->toBe([0.001]);
    }

    #[Test]
    public function temporalFailureLocationPointsAtTheMatcherCall(): void
    {
        $line = __LINE__ + 1;
        $detail = FailureProbe::detailOf(static fn() => expect()->calling(static fn(): int => 1)->returnValue()->eventually()->within(0.001)->toBe(2));

        expect($detail->location?->file)->toBe(__FILE__);
        expect($detail->location?->line)->toBe($line);
    }

    #[Test]
    public function eventuallyReusesAOneShotIterableAcrossRetries(): void
    {
        $clock = new FakeClock();
        $values = ['pending', 'ready'];
        $calls = 0;
        $haystack = (static function (): \Generator {
            yield 'ready';
        })();

        ExpectationRuntime::withClock($clock, static function () use (&$calls, $values, $haystack): void {
            expect()->calling(static function () use (&$calls, $values): string {
                return $values[$calls++];
            })->returnValue()->eventually()
                ->pollEvery(0.010)
                ->within(0.100)
                ->toBeIn($haystack);
        });

        expect($calls)
            ->because('eventually() reuses a one-shot iterable across retries')
            ->toBe(2);
        expect($clock->sleeps)
            ->toEqual([0.010]);
    }

    #[Test]
    public function eventuallyFailureKeepsTheFinalDiffAndBoundedHistory(): void
    {
        $clock = new FakeClock();
        $value = 0;

        $detail = FailureProbe::detailOf(static function () use ($clock, &$value): void {
            ExpectationRuntime::withClock(
                $clock,
                static fn() => expect()->calling(static function () use (&$value): int {
                    return ++$value;
                })->returnValue()->eventually()
                    ->pollEvery(0.010)
                    ->within(0.030)
                    ->toEqual(99),
            );
        });

        expect($detail->message)->because('eventually() failure keeps the final diff and bounded history')->toBe(
            'The eventually() expectation did not pass within 0.030 seconds after 4 observations. '
            . 'Last failure: Expected 4 to equal 99. Observations: '
            . "+0.0ms 1\n+10.0ms 2\n+20.0ms 3\n+30.0ms 4.",
        );
        expect($detail->expected)->toBe('99');
        expect($detail->actual)->toBe('4');
        expect($detail->location?->file)->toBe(__FILE__);
    }

    #[Test]
    public function eventuallyNegationWaitsUntilTheNegatedMatcherPasses(): void
    {
        $clock = new FakeClock();
        $values = ['busy', 'busy', 'idle'];
        $calls = 0;

        ExpectationRuntime::withClock(
            $clock,
            static function () use (&$calls, $values): void {
                expect()->calling(static function () use (&$calls, $values): string {
                    return $values[$calls++];
                })->returnValue()->eventually()
                    ->pollEvery(0.010)
                    ->within(0.100)
                    ->not()
                    ->toBe('busy');
            },
        );

        expect($calls)->because('eventually() negation waits until the negated matcher passes')->toBe(3);
    }

    #[Test]
    public function eventuallyReportsWhenNoAttemptTimeRemains(): void
    {
        $clock = new FakeClock();
        ExpectationRuntime::enterAttempt(0.0);

        try {
            $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
                $clock,
                static fn() => expect()->calling(static fn(): string => 'pending')->returnValue()->eventually()
                    ->within(1.000)
                    ->toBe('ready'),
            ));
        } finally {
            ExpectationRuntime::leaveAttempt();
        }

        expect($detail->message)->toBe(
            'No time remains for the requested 1.000-second eventually() wait.',
        );
    }

    #[Test]
    public function probeExceptionsPropagateUnlessExplicitlyRetryable(): void
    {
        $clock = new FakeClock();
        $failure = new TransientProbeFailure('not ready');

        expect()->calling(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => expect()->calling(
                static fn(): never => throw $failure,
            )->returnValue()->eventually()->within(0.100)->toBe('ready'),
        ))->because('probe exceptions propagate unless explicitly retryable')->toThrow($failure);

        $calls = 0;
        ExpectationRuntime::withClock($clock, static function () use (&$calls): void {
            expect()->calling(static function () use (&$calls): string {
                if (++$calls < 3) {
                    throw new TransientProbeFailure('not ready');
                }

                return 'ready';
            })->returnValue()->eventually()
                ->retryOnException(TransientProbeFailure::class)
                ->pollEvery(0.010)
                ->within(0.100)
                ->toBe('ready');
        });

        expect($calls)->because('probe exceptions propagate unless explicitly retryable')->toBe(3);
    }

    #[Test]
    public function eventuallyFailurePreservesRetryableExceptionDiagnostics(): void
    {
        $clock = new FakeClock();
        $rendered = \sprintf(
            "threw %s with message 'not ready'",
            TransientProbeFailure::class,
        );

        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => expect()->calling(
                static fn(): never => throw new TransientProbeFailure('not ready'),
            )->returnValue()->eventually()
                ->retryOnException(TransientProbeFailure::class)
                ->pollEvery(0.010)
                ->within(0.020)
                ->toBe('ready'),
        ));

        expect($detail->message)
            ->because('eventually() failure preserves retryable exception diagnostics')
            ->toBe(
                'The eventually() expectation did not pass within 0.020 seconds after 3 observations. '
                . "Observations: +0.0ms {$rendered} (×3).",
            );
        expect($detail->expected)
            ->toBeNull();
        expect($detail->actual)
            ->toBe($rendered);
    }

    #[Test]
    public function errorsAndMatcherMisuseAreNeverRetried(): void
    {
        $clock = new FakeClock();
        $calls = 0;
        $failure = new \Error('programming error');

        expect()->calling(static function () use ($clock, &$calls): void {
            ExpectationRuntime::withClock($clock, static function () use (&$calls): void {
                expect()->calling(static function () use (&$calls): string { // @phpstan-ignore greenlight.expectationArgument.pattern (deliberately invalid: tests runtime validation)
                    ++$calls;

                    return 'value';
                })->returnValue()->eventually()
                    ->retryOnException(\Exception::class)
                    ->within(0.100)
                    ->toMatch('/invalid');
            });
        })->because('errors and matcher misuse are never retried')->toThrow(\InvalidArgumentException::class);

        expect($calls)->because('errors and matcher misuse are never retried')->toBe(1);

        expect()->calling(static function () use ($clock, &$calls, $failure): void {
            ExpectationRuntime::withClock($clock, static function () use (&$calls, $failure): void {
                expect()->calling(static function () use (&$calls, $failure): never {
                    ++$calls;

                    throw $failure;
                })->returnValue()->eventually()
                    ->retryOnException(\Exception::class)
                    ->within(0.100)
                    ->toBe('unreachable');
            });
        })->because('errors and matcher misuse are never retried')->toThrow($failure);

        expect($calls)->because('errors and matcher misuse are never retried')->toBe(2);
    }

    #[Test]
    public function consistentlySamplesThroughTheWholePeriod(): void
    {
        $clock = new FakeClock();
        $calls = 0;

        ExpectationRuntime::withClock(
            $clock,
            static function () use (&$calls): void {
                expect()->calling(static function () use (&$calls): string {
                    ++$calls;

                    return 'stable';
                })->returnValue()->consistently()
                    ->pollEvery(0.010)
                    ->for(0.030)
                    ->toBe('stable')
                    ->toStartWith('sta');
            },
        );

        expect($calls)->because('consistently() samples through the whole period')->toBe(4);
    }

    #[Test]
    public function consistentlyFailsOnTheFirstViolation(): void
    {
        $clock = new FakeClock();
        $values = ['stable', 'stable', 'changed', 'stable'];
        $calls = 0;

        $detail = FailureProbe::detailOf(static function () use ($clock, $values, &$calls): void {
            ExpectationRuntime::withClock($clock, static function () use ($values, &$calls): void {
                expect()->calling(static function () use ($values, &$calls): string {
                    return $values[$calls++];
                })->returnValue()->consistently()
                    ->pollEvery(0.010)
                    ->for(0.100)
                    ->toBe('stable');
            });
        });

        expect($calls)->because('consistently() fails on the first violation')->toBe(3);
        expect($detail->message)->toBe(
            'The consistently() expectation failed after 0.020 seconds and 3 observations. '
                . "Last failure: Expected 'changed' to be 'stable'. Observations: "
                . "+0.0ms 'stable' (×2)\n+20.0ms 'changed'.",
        );
        expect($detail->expected)->toBe("'stable'");
        expect($detail->actual)->toBe("'changed'");
    }

    #[Test]
    public function consistentlyReportsAFirstObservationFailure(): void
    {
        $clock = new FakeClock();

        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => expect()->calling(static fn(): string => 'changed')->returnValue()->consistently()
                ->for(1.000)
                ->toBe('stable'),
        ));

        expect($detail->message)->toBe(
            'The consistently() expectation failed on the first observation. '
            . "Last failure: Expected 'changed' to be 'stable'. "
            . "Observations: +0.0ms 'changed'.",
        );
    }

    #[Test]
    public function consistentlyReportsWhenNoAttemptTimeRemains(): void
    {
        $clock = new FakeClock();
        ExpectationRuntime::enterAttempt(0.0);

        try {
            $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
                $clock,
                static fn() => expect()->calling(static fn(): string => 'stable')->returnValue()->consistently()
                    ->for(1.000)
                    ->toBe('stable'),
            ));
        } finally {
            ExpectationRuntime::leaveAttempt();
        }

        expect($detail->message)->toBe(
            'No time remains for the requested 1.000-second consistently() observation period. '
            . "Observations: +0.0ms 'stable'.",
        );
    }

    #[Test]
    public function aTemporalMatcherCountsAsOneExpectation(): void
    {
        $clock = new FakeClock();
        ExpectationCounter::reset();

        ExpectationRuntime::withClock(
            $clock,
            static fn() => expect()->calling(static fn(): int => 1)->returnValue()->eventually()->within(0.100)->toBe(1),
        );
        $count = ExpectationCounter::count();

        expect($count)->because('a temporal matcher counts as one expectation')->toBe(1);
    }

    #[Test]
    public function temporalExpectationsDispatchConfiguredExtensionMatchers(): void
    {
        $clock = new FakeClock();
        $subjects = [-1, 2];
        $restoreExtensions = Expect::install([new PositiveNumbersExtension()]);
        $this->cleanup->defer($restoreExtensions);

        ExpectationRuntime::withClock(
            $clock,
            static fn() => expect()->calling(static function () use (&$subjects): int {
                return \array_shift($subjects) ?? 2;
            })->returnValue()->eventually()
                ->within(0.100)
                ->__call('toBePositive', []),
        );

        expect($clock->sleeps)
            ->because('the extension matcher MUST reject the negative probe before it accepts the positive probe')
            ->toBe([0.025]);
    }

    #[Test]
    public function theOuterTestDeadlineTruncatesAnEventuallyWait(): void
    {
        $clock = new FakeClock();
        ExpectationRuntime::enterAttempt(0.015);

        try {
            $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
                $clock,
                static fn() => expect()->calling(static fn(): string => 'pending')->returnValue()->eventually()
                    ->pollEvery(0.010)
                    ->within(1.000)
                    ->toBe('ready'),
            ));
        } finally {
            ExpectationRuntime::leaveAttempt();
        }

        expect($detail->message)->because('the outer test deadline truncates an eventually wait')->toBe(
            'The test time limit stopped the eventually() expectation after 3 observations. '
            . 'The requested wait was 1.000 seconds. '
            . "Last failure: Expected 'pending' to be 'ready'. "
            . "Observations: +0.0ms 'pending' (×3).",
        );
    }

    #[Test]
    public function theOuterTestDeadlineTruncatesAConsistentlyObservationPeriod(): void
    {
        $clock = new FakeClock();
        ExpectationRuntime::enterAttempt(0.015);

        try {
            $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
                $clock,
                static fn() => expect()->calling(static fn(): string => 'stable')->returnValue()->consistently()
                    ->pollEvery(0.010)
                    ->for(1.000)
                    ->toBe('stable'),
            ));
        } finally {
            ExpectationRuntime::leaveAttempt();
        }

        expect($detail->message)->toBe(
            'The test time limit ended the consistently() expectation early. '
            . 'The requested observation period was 1.000 seconds. '
            . "Observations: +0.0ms 'stable' (×3).",
        );
    }

    #[Test]
    public function pollingDurationsAndExceptionTypesAreValidated(): void
    {
        expect()->calling(static fn() => expect()->calling(static fn(): int => 1)->returnValue()->eventually()->within(0.0))->because('polling durations and exception types are validated') // @phpstan-ignore greenlight.expectationArgument.duration (deliberately invalid: tests runtime validation)
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Set Eventually duration to a finite value greater than 0.000 seconds.',
            );
        expect()->calling(static fn() => expect()->calling(static fn(): int => 1)->returnValue()->eventually()->pollEvery(0.0009))->because('polling durations and exception types are validated') // @phpstan-ignore greenlight.expectationArgument.duration (deliberately invalid: tests runtime validation)
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Set Polling interval to a finite value of at least 0.001 seconds.',
            );
        expect()->calling(static fn() => expect()->calling(static fn(): int => 1)->returnValue()->consistently()->for(\NAN))->because('polling durations and exception types are validated') // @phpstan-ignore greenlight.expectationArgument.duration (deliberately invalid: tests runtime validation)
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use a finite consistency duration greater than 0.000 seconds.',
            );
        expect()->calling(static fn() => expect()->calling(static fn(): int => 1)->returnValue()->consistently()->pollEvery(0.0009))->because('polling durations and exception types are validated') // @phpstan-ignore greenlight.expectationArgument.duration (deliberately invalid: tests runtime validation)
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use a finite polling interval of at least 0.001 seconds.',
            );
        expect()->calling(static function (): void {
            new \ReflectionMethod(PendingEventually::class, 'retryOnException')
                ->invoke(expect()->calling(static fn(): int => 1)->returnValue()->eventually(), \Error::class);
        })->because('polling durations and exception types are validated')->toThrow(\InvalidArgumentException::class);

        $probed = false;
        expect()->calling(static function () use (&$probed): void {
            $eventually = expect()->calling(static function () use (&$probed) {
                $probed = true;

            })->eventually()
                ->within(0.100);
            $eventually->toThrow(\RuntimeException::class, matching: '/x/', message: 'x'); // @phpstan-ignore greenlight.toThrow.messageConstraint (deliberately invalid: tests runtime validation)
        })->because('polling durations and exception types are validated')->toThrow(
            ExpectationFailed::class,
            matching: '/^Specify matching: or message: for toThrow\(\)\. Do not specify both\./',
        );
        expect($probed)->because('polling durations and exception types are validated')->toBeFalse();
    }

    #[Test]
    public function consistentlyAcceptsTheMinimumPollingInterval(): void
    {
        $clock = new FakeClock();

        ExpectationRuntime::withClock($clock, static function (): void {
            expect()->calling(static fn(): int => 1)->returnValue()->consistently()
                ->pollEvery(0.001)
                ->for(0.001)
                ->toBe(1);
        });

        expect($clock->sleeps)
            ->because('the minimum consistency polling interval MUST remain valid')
            ->toBe([0.001]);
    }

    #[Test]
    public function eventuallyCarriesTheReasonIntoTheFailure(): void
    {
        $clock = new FakeClock();

        $detail = FailureProbe::detailOf(static function () use ($clock): void {
            ExpectationRuntime::withClock(
                $clock,
                static fn() => expect()->calling(static fn(): string => 'pending')->returnValue()->eventually()
                    ->pollEvery(0.010)
                    ->within(0.030)
                    ->because('the job must finish')
                    ->toBe('done'),
            );
        });

        expect($detail->message)->because('eventually() carries the reason into the failure')
            ->toContain('The eventually() expectation did not pass within 0.030 seconds')
            ->toContain("Last failure: Expected 'pending' to be 'done' because the job must finish.");
    }

    #[Test]
    public function consistentlyCarriesTheReasonIntoTheFailure(): void
    {
        $clock = new FakeClock();

        $detail = FailureProbe::detailOf(static function () use ($clock): void {
            ExpectationRuntime::withClock(
                $clock,
                static fn() => expect()->calling(static fn(): int => 1)->returnValue()->consistently()
                    ->for(0.030)
                    ->because('the queue must stay empty')
                    ->toBe(0),
            );
        });

        expect($detail->message)->because('consistently() carries the reason into the failure')
            ->toContain('The consistently() expectation failed on the first observation.')
            ->toContain('Last failure: Expected 1 to be 0 because the queue must stay empty.');
    }

    #[Test]
    public function temporalBecauseRequiresANonEmptyReason(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect()->calling(static fn(): bool => true)->returnValue()->eventually()->within(0.030)->because('   '), // @phpstan-ignore greenlight.expectationArgument.reason (deliberately invalid: tests runtime validation)
        );

        expect($detail->message)->because('temporal because requires a non empty reason')->toBe('because() requires a non-empty reason.');
    }

    #[Test]
    public function observationHistoryCollapsesRepeatsAndBoundsChanges(): void
    {
        $clock = new FakeClock();
        $value = 0;

        $detail = FailureProbe::detailOf(static function () use ($clock, &$value): void {
            ExpectationRuntime::withClock($clock, static function () use (&$value): void {
                expect()->calling(static function () use (&$value): int {
                    return (int) \floor($value++ / 2);
                })->returnValue()->eventually()
                    ->pollEvery(0.001)
                    ->within(0.010)
                    ->toBe(99);
            });
        });

        expect($detail->message)->because('observation history collapses repeats and bounds changes')->toContain('(×2)')
            ->toContain('earlier changes omitted');
    }

}
