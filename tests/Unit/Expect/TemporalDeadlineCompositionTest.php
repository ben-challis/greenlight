<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Result\FailureDetail;
use Greenlight\Tests\Fixture\Expect\FakePollingClock;

final class TemporalDeadlineCompositionTest
{
    #[Test]
    public function anEnclosingEventuallyWaitLimitsANestedEventuallyWait(): void
    {
        $clock = new FakePollingClock();
        $calls = 0;

        $detail = FailureProbe::detailOf(static function () use ($clock, &$calls): void {
            ExpectationRuntime::withClock($clock, static function () use (&$calls): void {
                Expect::eventually(static function () use (&$calls): bool {
                    Expect::eventually(static function () use (&$calls): bool {
                        ++$calls;

                        return false;
                    })->pollEvery(0.010)->within(0.100)->toBeTrue();

                    return true;
                })->within(0.020)->toBeTrue();
            });
        });

        Expect::that($clock->time)->toBe(0.020);
        Expect::that($calls)->toBe(3);
        Expect::that($detail->message)->toContain('enclosing expectation')->not()->toContain('test time limit');
    }

    #[Test]
    public function anEnclosingEventuallyWaitLimitsANestedConsistencyPeriod(): void
    {
        $clock = new FakePollingClock();
        $calls = 0;

        $detail = FailureProbe::detailOf(static function () use ($clock, &$calls): void {
            ExpectationRuntime::withClock($clock, static function () use (&$calls): void {
                Expect::eventually(static function () use (&$calls): bool {
                    Expect::consistently(static function () use (&$calls): bool {
                        ++$calls;

                        return true;
                    })->pollEvery(0.010)->for(0.100)->toBeTrue();

                    return true;
                })->within(0.020)->toBeTrue();
            });
        });

        Expect::that($clock->time)->toBe(0.020);
        Expect::that($calls)->toBe(3);
        Expect::that($detail->message)->toContain('enclosing expectation')->toContain('consistently()');
    }

    #[Test]
    public function anExpiredEnclosingDeadlinePreventsTheFirstEventuallyObservation(): void
    {
        $clock = new FakePollingClock();
        $calls = 0;

        $detail = FailureProbe::detailOf(static function () use ($clock, &$calls): void {
            ExpectationRuntime::withClock($clock, static function () use ($clock, &$calls): void {
                Expect::eventually(static function () use ($clock, &$calls): bool {
                    $clock->time = 0.020;
                    Expect::eventually(static function () use (&$calls): bool {
                        ++$calls;

                        return true;
                    })->within(0.100)->toBeTrue();

                    return true;
                })->within(0.020)->toBeTrue();
            });
        });

        Expect::that($calls)->toBe(0);
        Expect::that($detail->message)->toContain('enclosing expectation time limit left no time')
            ->toContain('eventually() wait');
    }

    #[Test]
    public function anExpiredEnclosingDeadlineStillAllowsTheFirstConsistencyObservation(): void
    {
        $clock = new FakePollingClock();
        $calls = 0;

        $detail = FailureProbe::detailOf(static function () use ($clock, &$calls): void {
            ExpectationRuntime::withClock($clock, static function () use ($clock, &$calls): void {
                Expect::eventually(static function () use ($clock, &$calls): bool {
                    $clock->time = 0.020;
                    Expect::consistently(static function () use (&$calls): bool {
                        ++$calls;

                        return true;
                    })->for(0.100)->toBeTrue();

                    return true;
                })->within(0.020)->toBeTrue();
            });
        });

        Expect::that($calls)->toBe(1);
        Expect::that($detail->message)->toContain('enclosing expectation time limit left no time')
            ->toContain('consistently() observation period');
    }

    #[Test]
    public function aConsistencyPeriodLimitsWaitsInLaterObservations(): void
    {
        $clock = new FakePollingClock();
        $calls = 0;

        $detail = FailureProbe::detailOf(static function () use ($clock, &$calls): void {
            ExpectationRuntime::withClock($clock, static function () use (&$calls): void {
                Expect::consistently(static function () use (&$calls): bool {
                    if (++$calls > 1) {
                        Expect::eventually(static fn(): bool => false)
                            ->pollEvery(0.010)->within(0.100)->toBeTrue();
                    }

                    return true;
                })->pollEvery(0.010)->for(0.030)->toBeTrue();
            });
        });

        Expect::that($clock->time)->toBe(0.030);
        Expect::that($calls)->toBe(2);
        Expect::that($detail->message)->toContain('enclosing expectation');
    }

    #[Test]
    public function nestedWaitsInMatcherCallbacksUseTheEnclosingDeadline(): void
    {
        $clock = new FakePollingClock();

        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => Expect::eventually(
                static fn(): \Closure => static fn(): never => throw new \RuntimeException('Ready.'),
            )->within(0.020)->toThrow(static function (\RuntimeException $exception): void {
                Expect::eventually(static fn(): bool => false)
                    ->pollEvery(0.010)->within(0.100)->toBeTrue();
            }),
        ));

        Expect::that($clock->time)->toBe(0.020);
        Expect::that($detail->message)->toContain('enclosing expectation');
    }

    #[Test]
    public function aPreviouslyConstructedExpectationUsesItsEnclosingDeadlineAtTheMatcherCall(): void
    {
        $clock = new FakePollingClock();

        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static function (): void {
                $inner = Expect::eventually(static fn(): bool => false)
                    ->pollEvery(0.010)->within(0.100);

                Expect::eventually(static function () use ($inner): bool {
                    $inner->toBeTrue();

                    return true;
                })->within(0.020)->toBeTrue();
            },
        ));

        Expect::that($clock->time)->toBe(0.020);
        Expect::that($detail->message)->toContain('enclosing expectation');
    }

    #[Test]
    public function aShorterLocalWaitKeepsItsOwnDeadlineAndDiagnostic(): void
    {
        $clock = new FakePollingClock();

        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => Expect::eventually(static function (): bool {
                Expect::eventually(static fn(): bool => false)
                    ->pollEvery(0.010)->within(0.020)->toBeTrue();

                return true;
            })->within(0.100)->toBeTrue(),
        ));

        Expect::that($clock->time)->toBe(0.020);
        Expect::that($detail->message)->toContain('did not pass within 0.020 seconds');
        Expect::that($detail->message)->not()->toContain('enclosing expectation');
    }

    #[Test]
    public function aShorterTestDeadlineKeepsTheTestLimitDiagnosticInNestedWaits(): void
    {
        $clock = new FakePollingClock();
        ExpectationRuntime::enterAttempt(0.015);

        try {
            $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
                $clock,
                static fn() => Expect::eventually(static function (): bool {
                    Expect::eventually(static fn(): bool => false)
                        ->pollEvery(0.010)->within(0.100)->toBeTrue();

                    return true;
                })->within(0.050)->toBeTrue(),
            ));
        } finally {
            ExpectationRuntime::leaveAttempt();
        }

        Expect::that($clock->time)->toBe(0.015);
        Expect::that($detail->message)->toContain('test time limit')->not()->toContain('enclosing expectation');
    }

    #[Test]
    public function aPreviouslyConstructedExpectationUsesTheCurrentTestDeadline(): void
    {
        $clock = new FakePollingClock();

        $detail = ExpectationRuntime::withClock($clock, static function (): FailureDetail {
            $expectation = Expect::eventually(static fn(): bool => false)
                ->pollEvery(0.010)->within(0.100);
            ExpectationRuntime::enterAttempt(0.020);

            try {
                return FailureProbe::detailOf(static fn() => $expectation->toBeTrue());
            } finally {
                ExpectationRuntime::leaveAttempt();
            }
        });

        Expect::that($clock->time)->toBe(0.020);
        Expect::that($detail->message)->toContain('test time limit');
    }

    #[Test]
    public function aProbeExceptionRestoresThePreviousDeadlineScope(): void
    {
        $clock = new FakePollingClock();
        $failure = new \RuntimeException('Probe failed.');

        ExpectationRuntime::withClock($clock, static function () use ($failure): void {
            Expect::that(static fn() => Expect::eventually(static fn(): never => throw $failure)
                ->within(0.010)->toBeTrue())->toThrow($failure);

            Expect::consistently(static fn(): bool => true)
                ->pollEvery(0.010)->for(0.030)->toBeTrue();
        });

        Expect::that($clock->time)->toBe(0.030);
    }

    #[Test]
    public function aNestedFailureRestoresTheEnclosingDeadline(): void
    {
        $clock = new FakePollingClock();

        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => Expect::eventually(static function (): bool {
                FailureProbe::detailOf(static fn() => Expect::eventually(static fn(): bool => false)
                    ->pollEvery(0.010)->within(0.010)->toBeTrue());

                Expect::eventually(static fn(): bool => false)
                    ->pollEvery(0.010)->within(0.100)->toBeTrue();

                return true;
            })->within(0.030)->toBeTrue(),
        ));

        Expect::that($clock->time)->toBe(0.030);
        Expect::that($detail->message)->toContain('enclosing expectation');
    }

    #[Test]
    public function nestedWaitsRestoreTheEnclosingDeadlineInTheSameFiber(): void
    {
        $clock = new FakePollingClock();

        ExpectationRuntime::withClock($clock, static function (): void {
            $fiber = new \Fiber(static function (): void {
                $detail = FailureProbe::detailOf(static fn() => Expect::eventually(static function (): bool {
                    Expect::eventually(static fn(): bool => true)->within(0.005)->toBeTrue();

                    FailureProbe::detailOf(static fn() => Expect::eventually(static fn(): bool => false)
                        ->pollEvery(0.010)->within(0.010)->toBeTrue());

                    Expect::eventually(static fn(): bool => false)
                        ->pollEvery(0.010)->within(0.100)->toBeTrue();

                    return true;
                })->within(0.030)->toBeTrue());

                Expect::that($detail->message)->toContain('enclosing expectation');
            });
            $fiber->start();

            Expect::that($fiber->isTerminated())->toBeTrue();
        });

        Expect::that($clock->time)->toBe(0.030);
    }

    #[Test]
    public function aNewFiberUsesTheCurrentTestDeadline(): void
    {
        $clock = new FakePollingClock();
        ExpectationRuntime::enterAttempt(0.015);

        try {
            ExpectationRuntime::withClock($clock, static function (): void {
                $fiber = new \Fiber(static function (): void {
                    $detail = FailureProbe::detailOf(static fn() => Expect::eventually(static fn(): bool => false)
                        ->pollEvery(0.010)->within(0.100)->toBeTrue());

                    Expect::that($detail->message)->toContain('test time limit')
                        ->not()->toContain('enclosing expectation');
                });
                $fiber->start();

                Expect::that($fiber->isTerminated())->toBeTrue();
            });
        } finally {
            ExpectationRuntime::leaveAttempt();
        }

        Expect::that($clock->time)->toBe(0.015);
    }

    #[Test]
    public function aSuspendedFiberDoesNotLimitTheMainContextOrASiblingFiber(): void
    {
        $clock = new FakePollingClock();

        ExpectationRuntime::withClock($clock, static function (): void {
            $suspended = new \Fiber(static function (): void {
                $detail = FailureProbe::detailOf(static fn() => Expect::eventually(static function (): bool {
                    \Fiber::suspend();

                    return true;
                })->within(0.010)->toBeTrue());

                Expect::that($detail->message)->toContain('did not pass within 0.010 seconds');
            });
            $suspended->start();

            Expect::consistently(static fn(): bool => true)
                ->pollEvery(0.010)->for(0.020)->toBeTrue();

            $sibling = new \Fiber(static function (): void {
                Expect::consistently(static fn(): bool => true)
                    ->pollEvery(0.010)->for(0.020)->toBeTrue();
            });
            $sibling->start();
            $suspended->resume();

            Expect::that($sibling->isTerminated())->toBeTrue();
            Expect::that($suspended->isTerminated())->toBeTrue();
        });

        Expect::that($clock->time)->toBe(0.040);
    }

    #[Test]
    public function aNewFiberDoesNotInheritTheMainContextDeadline(): void
    {
        $clock = new FakePollingClock();

        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => Expect::eventually(static function (): bool {
                $fiber = new \Fiber(static function (): void {
                    Expect::consistently(static fn(): bool => true)
                        ->pollEvery(0.010)->for(0.030)->toBeTrue();
                });
                $fiber->start();

                return true;
            })->within(0.010)->toBeTrue(),
        ));

        Expect::that($clock->time)->toBe(0.030);
        Expect::that($detail->message)->toContain('did not pass within 0.010 seconds');
    }

    #[Test]
    public function aResumedFiberDoesNotRestoreAScopeFromThePreviousAttempt(): void
    {
        $clock = new FakePollingClock();

        ExpectationRuntime::withClock($clock, static function (): void {
            ExpectationRuntime::enterAttempt(0.050);

            try {
                $fiber = new \Fiber(static function (): void {
                    $detail = FailureProbe::detailOf(static fn() => Expect::eventually(static function (): bool {
                        Expect::eventually(static function (): bool {
                            \Fiber::suspend();

                            return true;
                        })->within(0.010)->toBeTrue();

                        Expect::consistently(static fn(): bool => true)
                            ->pollEvery(0.010)->for(0.030)->toBeTrue();

                        return true;
                    })->within(0.020)->toBeTrue());

                    Expect::that($detail->message)->toContain('did not pass within 0.020 seconds');
                });
                $fiber->start();
                ExpectationRuntime::leaveAttempt();
                ExpectationRuntime::enterAttempt(0.100);
                $fiber->resume();

                Expect::that($fiber->isTerminated())->toBeTrue();
            } finally {
                ExpectationRuntime::leaveAttempt();
            }
        });

        Expect::that($clock->time)->toBe(0.030);
    }

    #[Test]
    public function theFirstConsistencyObservationUsesTheEnclosingDeadline(): void
    {
        $clock = new FakePollingClock();

        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => Expect::eventually(static function (): bool {
                Expect::consistently(static function (): bool {
                    Expect::eventually(static fn(): bool => false)
                        ->pollEvery(0.010)->within(0.100)->toBeTrue();

                    return true;
                })->for(0.100)->toBeTrue();

                return true;
            })->within(0.020)->toBeTrue(),
        ));

        Expect::that($clock->time)->toBe(0.020);
        Expect::that($detail->message)->toContain('enclosing expectation');
    }

    #[Test]
    public function aConsistencyPeriodStartsAfterItsFirstSuccessfulNestedWait(): void
    {
        $clock = new FakePollingClock();
        $calls = 0;

        ExpectationRuntime::withClock($clock, static function () use ($clock, &$calls): void {
            Expect::consistently(static function () use ($clock, &$calls): bool {
                if (++$calls === 1) {
                    Expect::eventually(static fn(): bool => $clock->time >= 0.020)
                        ->pollEvery(0.010)->within(0.100)->toBeTrue();
                }

                return true;
            })->pollEvery(0.010)->for(0.010)->toBeTrue();
        });

        Expect::that($clock->time)->toBe(0.030);
        Expect::that($calls)->toBe(2);
        Expect::that($clock->sleeps)->toHaveCount(3);
    }
}
