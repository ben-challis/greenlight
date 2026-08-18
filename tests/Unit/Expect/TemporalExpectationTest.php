<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\ExpectationCounter;
use Greenlight\Expect\EventuallyExpectation;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Expectation;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Expect\PendingEventually;
use Greenlight\Expect\TemporalExpectation;
use Greenlight\Tests\Fixture\Expect\FakePollingClock;
use Greenlight\Tests\Fixture\Expect\PositiveNumbersExtension;
use Greenlight\Tests\Fixture\Expect\TransientProbeFailure;

final class TemporalExpectationTest
{
    #[Test]
    public function temporalAndImmediateExpectationsExposeTheSameNativeMatchers(): void
    {
        Expect::that($this->matcherSignatures(Expectation::class))->because('temporal and immediate expectations expose the same native matchers')
            ->toEqual($this->matcherSignatures(TemporalExpectation::class));
    }

    #[Test]
    public function eventuallyStopsAtTheFirstMatchingObservation(): void
    {
        $clock = new FakePollingClock();
        $values = ['pending', 'pending', 'ready'];
        $calls = 0;

        ExpectationRuntime::withClock($clock, static function () use (&$calls, $values): void {
            Expect::eventually(static function () use (&$calls, $values): string {
                return $values[$calls++];
            })
                ->pollEvery(0.010)
                ->within(0.100)
                ->toEqual('ready')
                ->toBe('ready');
        });

        Expect::that($calls)->because('eventually() stops at the first matching observation')->toBe(3);
        Expect::that($clock->sleeps)->toEqual([0.010, 0.010]);
    }

    #[Test]
    public function eventuallyReusesAOneShotIterableAcrossRetries(): void
    {
        $clock = new FakePollingClock();
        $values = ['pending', 'ready'];
        $calls = 0;
        $haystack = (static function (): \Generator {
            yield 'ready';
        })();

        ExpectationRuntime::withClock($clock, static function () use (&$calls, $values, $haystack): void {
            Expect::eventually(static function () use (&$calls, $values): string {
                return $values[$calls++];
            })
                ->pollEvery(0.010)
                ->within(0.100)
                ->toBeIn($haystack);
        });

        Expect::that($calls)
            ->because('eventually() reuses a one-shot iterable across retries')
            ->toBe(2);
        Expect::that($clock->sleeps)
            ->toEqual([0.010]);
    }

    #[Test]
    public function eventuallyFailureKeepsTheFinalDiffAndBoundedHistory(): void
    {
        $clock = new FakePollingClock();
        $value = 0;

        $detail = FailureProbe::detailOf(static function () use ($clock, &$value): void {
            ExpectationRuntime::withClock(
                $clock,
                static fn() => Expect::eventually(static function () use (&$value): int {
                    return ++$value;
                })
                    ->pollEvery(0.010)
                    ->within(0.030)
                    ->toEqual(99),
            );
        });

        Expect::that($detail->message)->because('eventually() failure keeps the final diff and bounded history')->toBe(
            'The eventually() expectation did not pass within 0.030 seconds after 4 observations. '
            . 'Last failure: Expected 4 to equal 99. Observations: '
            . "+0.0ms 1\n+10.0ms 2\n+20.0ms 3\n+30.0ms 4.",
        );
        Expect::that($detail->expected)->toBe('99');
        Expect::that($detail->actual)->toBe('4');
        Expect::that($detail->location?->file)->toBe(__FILE__);
    }

    #[Test]
    public function eventuallyNegationWaitsUntilTheNegatedMatcherPasses(): void
    {
        $clock = new FakePollingClock();
        $values = ['busy', 'busy', 'idle'];
        $calls = 0;

        ExpectationRuntime::withClock(
            $clock,
            static function () use (&$calls, $values): void {
                Expect::eventually(static function () use (&$calls, $values): string {
                    return $values[$calls++];
                })
                    ->pollEvery(0.010)
                    ->within(0.100)
                    ->not()
                    ->toBe('busy');
            },
        );

        Expect::that($calls)->because('eventually() negation waits until the negated matcher passes')->toBe(3);
    }

    #[Test]
    public function eventuallyReportsWhenNoAttemptTimeRemains(): void
    {
        $clock = new FakePollingClock();
        ExpectationRuntime::enterAttempt(0.0);

        try {
            $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
                $clock,
                static fn() => Expect::eventually(static fn(): string => 'pending')
                    ->within(1.000)
                    ->toBe('ready'),
            ));
        } finally {
            ExpectationRuntime::leaveAttempt();
        }

        Expect::that($detail->message)->toBe(
            'No time remains for the requested 1.000-second eventually() wait.',
        );
    }

    #[Test]
    public function probeExceptionsPropagateUnlessExplicitlyRetryable(): void
    {
        $clock = new FakePollingClock();
        $failure = new TransientProbeFailure('not ready');

        Expect::that(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => Expect::eventually(
                static fn(): never => throw $failure,
            )->within(0.100)->toBe('ready'),
        ))->because('probe exceptions propagate unless explicitly retryable')->toThrow($failure);

        $calls = 0;
        ExpectationRuntime::withClock($clock, static function () use (&$calls): void {
            Expect::eventually(static function () use (&$calls): string {
                if (++$calls < 3) {
                    throw new TransientProbeFailure('not ready');
                }

                return 'ready';
            })
                ->retryOnException(TransientProbeFailure::class)
                ->pollEvery(0.010)
                ->within(0.100)
                ->toBe('ready');
        });

        Expect::that($calls)->because('probe exceptions propagate unless explicitly retryable')->toBe(3);
    }

    #[Test]
    public function eventuallyFailurePreservesRetryableExceptionDiagnostics(): void
    {
        $clock = new FakePollingClock();
        $rendered = \sprintf(
            "threw %s with message 'not ready'",
            TransientProbeFailure::class,
        );

        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => Expect::eventually(
                static fn(): never => throw new TransientProbeFailure('not ready'),
            )
                ->retryOnException(TransientProbeFailure::class)
                ->pollEvery(0.010)
                ->within(0.020)
                ->toBe('ready'),
        ));

        Expect::that($detail->message)
            ->because('eventually() failure preserves retryable exception diagnostics')
            ->toBe(
                'The eventually() expectation did not pass within 0.020 seconds after 3 observations. '
                . "Observations: +0.0ms {$rendered} (×3).",
            );
        Expect::that($detail->expected)
            ->toBeNull();
        Expect::that($detail->actual)
            ->toBe($rendered);
    }

    #[Test]
    public function errorsAndMatcherMisuseAreNeverRetried(): void
    {
        $clock = new FakePollingClock();
        $calls = 0;
        $failure = new \Error('programming error');

        Expect::that(static function () use ($clock, &$calls): void {
            ExpectationRuntime::withClock($clock, static function () use (&$calls): void {
                Expect::eventually(static function () use (&$calls): string { // @phpstan-ignore greenlight.expectationArgument.pattern (deliberately invalid: tests runtime validation)
                    ++$calls;

                    return 'value';
                })
                    ->retryOnException(\Exception::class)
                    ->within(0.100)
                    ->toMatch('/invalid');
            });
        })->because('errors and matcher misuse are never retried')->toThrow(\InvalidArgumentException::class);

        Expect::that($calls)->because('errors and matcher misuse are never retried')->toBe(1);

        Expect::that(static function () use ($clock, &$calls, $failure): void {
            ExpectationRuntime::withClock($clock, static function () use (&$calls, $failure): void {
                Expect::eventually(static function () use (&$calls, $failure): never {
                    ++$calls;

                    throw $failure;
                })
                    ->retryOnException(\Exception::class)
                    ->within(0.100)
                    ->toBe('unreachable');
            });
        })->because('errors and matcher misuse are never retried')->toThrow($failure);

        Expect::that($calls)->because('errors and matcher misuse are never retried')->toBe(2);
    }

    #[Test]
    public function consistentlySamplesThroughTheWholePeriod(): void
    {
        $clock = new FakePollingClock();
        $calls = 0;

        ExpectationRuntime::withClock(
            $clock,
            static function () use (&$calls): void {
                Expect::consistently(static function () use (&$calls): string {
                    ++$calls;

                    return 'stable';
                })
                    ->pollEvery(0.010)
                    ->for(0.030)
                    ->toBe('stable')
                    ->toStartWith('sta');
            },
        );

        Expect::that($calls)->because('consistently() samples through the whole period')->toBe(4);
    }

    #[Test]
    public function consistentlyFailsOnTheFirstViolation(): void
    {
        $clock = new FakePollingClock();
        $values = ['stable', 'stable', 'changed', 'stable'];
        $calls = 0;

        $detail = FailureProbe::detailOf(static function () use ($clock, $values, &$calls): void {
            ExpectationRuntime::withClock($clock, static function () use ($values, &$calls): void {
                Expect::consistently(static function () use ($values, &$calls): string {
                    return $values[$calls++];
                })
                    ->pollEvery(0.010)
                    ->for(0.100)
                    ->toBe('stable');
            });
        });

        Expect::that($calls)->because('consistently() fails on the first violation')->toBe(3);
        Expect::that($detail->message)->toBe(
            'The consistently() expectation failed after 0.020 seconds and 3 observations. '
                . "Last failure: Expected 'changed' to be 'stable'. Observations: "
                . "+0.0ms 'stable' (×2)\n+20.0ms 'changed'.",
        );
        Expect::that($detail->expected)->toBe("'stable'");
        Expect::that($detail->actual)->toBe("'changed'");
    }

    #[Test]
    public function consistentlyReportsAFirstObservationFailure(): void
    {
        $clock = new FakePollingClock();

        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => Expect::consistently(static fn(): string => 'changed')
                ->for(1.000)
                ->toBe('stable'),
        ));

        Expect::that($detail->message)->toBe(
            'The consistently() expectation failed on the first observation. '
            . "Last failure: Expected 'changed' to be 'stable'. "
            . "Observations: +0.0ms 'changed'.",
        );
    }

    #[Test]
    public function consistentlyReportsWhenNoAttemptTimeRemains(): void
    {
        $clock = new FakePollingClock();
        ExpectationRuntime::enterAttempt(0.0);

        try {
            $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
                $clock,
                static fn() => Expect::consistently(static fn(): string => 'stable')
                    ->for(1.000)
                    ->toBe('stable'),
            ));
        } finally {
            ExpectationRuntime::leaveAttempt();
        }

        Expect::that($detail->message)->toBe(
            'No time remains for the requested 1.000-second consistently() observation period. '
            . "Observations: +0.0ms 'stable'.",
        );
    }

    #[Test]
    public function aTemporalMatcherCountsAsOneExpectation(): void
    {
        $clock = new FakePollingClock();
        ExpectationCounter::reset();

        ExpectationRuntime::withClock(
            $clock,
            static fn() => Expect::eventually(static fn(): int => 1)->within(0.100)->toBe(1),
        );
        $count = ExpectationCounter::count();

        Expect::that($count)->because('a temporal matcher counts as one expectation')->toBe(1);
    }

    #[Test]
    public function temporalExpectationsDispatchConfiguredExtensionMatchers(): void
    {
        $clock = new FakePollingClock();
        $subjects = [-1, 2];
        Expect::install([new PositiveNumbersExtension()]);

        try {
            ExpectationRuntime::withClock(
                $clock,
                static fn() => Expect::eventually(static function () use (&$subjects): int {
                    return \array_shift($subjects) ?? 2;
                })
                    ->within(0.100)
                    ->__call('toBePositive', []),
            );
        } finally {
            Expect::install([]);
        }

        Expect::that($clock->sleeps)
            ->because('the extension matcher MUST reject the negative probe before it accepts the positive probe')
            ->toBe([0.025]);
    }

    #[Test]
    public function theOuterTestDeadlineTruncatesAnEventuallyWait(): void
    {
        $clock = new FakePollingClock();
        ExpectationRuntime::enterAttempt(0.015);

        try {
            $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
                $clock,
                static fn() => Expect::eventually(static fn(): string => 'pending')
                    ->pollEvery(0.010)
                    ->within(1.000)
                    ->toBe('ready'),
            ));
        } finally {
            ExpectationRuntime::leaveAttempt();
        }

        Expect::that($detail->message)->because('the outer test deadline truncates an eventually wait')->toBe(
            'The test time limit stopped the eventually() expectation after 3 observations. '
            . 'The requested wait was 1.000 seconds. '
            . "Last failure: Expected 'pending' to be 'ready'. "
            . "Observations: +0.0ms 'pending' (×3).",
        );
    }

    #[Test]
    public function theOuterTestDeadlineTruncatesAConsistentlyObservationPeriod(): void
    {
        $clock = new FakePollingClock();
        ExpectationRuntime::enterAttempt(0.015);

        try {
            $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
                $clock,
                static fn() => Expect::consistently(static fn(): string => 'stable')
                    ->pollEvery(0.010)
                    ->for(1.000)
                    ->toBe('stable'),
            ));
        } finally {
            ExpectationRuntime::leaveAttempt();
        }

        Expect::that($detail->message)->toBe(
            'The test time limit ended the consistently() expectation early. '
            . 'The requested observation period was 1.000 seconds. '
            . "Observations: +0.0ms 'stable' (×3).",
        );
    }

    #[Test]
    public function pollingDurationsAndExceptionTypesAreValidated(): void
    {
        Expect::that(static fn() => Expect::eventually(static fn(): int => 1)->within(0.0))->because('polling durations and exception types are validated') // @phpstan-ignore greenlight.expectationArgument.duration (deliberately invalid: tests runtime validation)
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Set Eventually duration to a finite value of at least 0.000 seconds.',
            );
        Expect::that(static fn() => Expect::eventually(static fn(): int => 1)->pollEvery(0.0009))->because('polling durations and exception types are validated') // @phpstan-ignore greenlight.expectationArgument.duration (deliberately invalid: tests runtime validation)
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Set Polling interval to a finite value of at least 0.001 seconds.',
            );
        Expect::that(static fn() => Expect::consistently(static fn(): int => 1)->for(\NAN))->because('polling durations and exception types are validated') // @phpstan-ignore greenlight.expectationArgument.duration (deliberately invalid: tests runtime validation)
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use a finite consistency duration greater than 0.000 seconds.',
            );
        Expect::that(static fn() => Expect::consistently(static fn(): int => 1)->pollEvery(0.0009))->because('polling durations and exception types are validated') // @phpstan-ignore greenlight.expectationArgument.duration (deliberately invalid: tests runtime validation)
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use a finite polling interval of at least 0.001 seconds.',
            );
        Expect::that(static function (): void {
            new \ReflectionMethod(PendingEventually::class, 'retryOnException')
                ->invoke(Expect::eventually(static fn(): int => 1), \Error::class);
        })->because('polling durations and exception types are validated')->toThrow(\InvalidArgumentException::class);

        $probed = false;
        Expect::that(static function () use (&$probed): void {
            $eventually = Expect::eventually(static function () use (&$probed): \Closure {
                $probed = true;

                return static function (): void {};
            })
                ->within(0.100);
            new \ReflectionMethod(EventuallyExpectation::class, 'toThrow')
                ->invoke($eventually, \RuntimeException::class, '/x/', 'x');
        })->because('polling durations and exception types are validated')->toThrow(
            ExpectationFailed::class,
            matching: '/^Specify matching: or message: for toThrow\(\)\. Do not specify both\./',
        );
        Expect::that($probed)->because('polling durations and exception types are validated')->toBeFalse();
    }

    #[Test]
    public function consistentlyAcceptsTheMinimumPollingInterval(): void
    {
        $clock = new FakePollingClock();

        ExpectationRuntime::withClock($clock, static function (): void {
            Expect::consistently(static fn(): int => 1)
                ->pollEvery(0.001)
                ->for(0.001)
                ->toBe(1);
        });

        Expect::that($clock->sleeps)
            ->because('the minimum consistency polling interval MUST remain valid')
            ->toBe([0.001]);
    }

    #[Test]
    public function eventuallyCarriesTheReasonIntoTheFailure(): void
    {
        $clock = new FakePollingClock();

        $detail = FailureProbe::detailOf(static function () use ($clock): void {
            ExpectationRuntime::withClock(
                $clock,
                static fn() => Expect::eventually(static fn(): string => 'pending')
                    ->pollEvery(0.010)
                    ->within(0.030)
                    ->because('the job must finish')
                    ->toBe('done'),
            );
        });

        Expect::that($detail->message)->because('eventually() carries the reason into the failure')
            ->toContain('The eventually() expectation did not pass within 0.030 seconds')
            ->toContain("Last failure: Expected 'pending' to be 'done' because the job must finish.");
    }

    #[Test]
    public function consistentlyCarriesTheReasonIntoTheFailure(): void
    {
        $clock = new FakePollingClock();

        $detail = FailureProbe::detailOf(static function () use ($clock): void {
            ExpectationRuntime::withClock(
                $clock,
                static fn() => Expect::consistently(static fn(): int => 1)
                    ->for(0.030)
                    ->because('the queue must stay empty')
                    ->toBe(0),
            );
        });

        Expect::that($detail->message)->because('consistently() carries the reason into the failure')
            ->toContain('The consistently() expectation failed on the first observation.')
            ->toContain('Last failure: Expected 1 to be 0 because the queue must stay empty.');
    }

    #[Test]
    public function temporalBecauseRequiresANonEmptyReason(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::eventually(static fn(): bool => true)->within(0.030)->because('   '),
        );

        Expect::that($detail->message)->because('temporal because requires a non empty reason')->toBe('because() requires a non-empty reason.');
    }

    #[Test]
    public function observationHistoryCollapsesRepeatsAndBoundsChanges(): void
    {
        $clock = new FakePollingClock();
        $value = 0;

        $detail = FailureProbe::detailOf(static function () use ($clock, &$value): void {
            ExpectationRuntime::withClock($clock, static function () use (&$value): void {
                Expect::eventually(static function () use (&$value): int {
                    return (int) \floor($value++ / 2);
                })
                    ->pollEvery(0.001)
                    ->within(0.010)
                    ->toBe(99);
            });
        });

        Expect::that($detail->message)->because('observation history collapses repeats and bounds changes')->toContain('(×2)')
            ->toContain('earlier changes omitted');
    }

    /**
     * @param class-string $class
     *
     * @return array<string, list<string>>
     */
    private function matcherSignatures(string $class): array
    {
        $signatures = [];

        foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!\str_starts_with($method->getName(), 'to')) {
                continue;
            }

            $signatures[$method->getName()] = \array_map(
                static fn(\ReflectionParameter $parameter): string => \sprintf(
                    '%s %s%s%s',
                    (string) $parameter->getType(),
                    $parameter->isVariadic() ? '...' : '',
                    $parameter->getName(),
                    $parameter->isOptional() && !$parameter->isVariadic() ? '?' : '',
                ),
                $method->getParameters(),
            );
        }

        \ksort($signatures);

        return $signatures;
    }
}
