<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Amp;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Greenlight\Amp\AmpBridgeError;
use Greenlight\Amp\AmpContext;
use Greenlight\Amp\AmpPlugin;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Test\DeadlineExceededError;
use Greenlight\Test\ExpectationCounter;
use Revolt\EventLoop;

final class AmpContextTest
{
    #[Test]
    public function otherFibersProgressDuringTemporalPolling(): void
    {
        $ready = false;

        self::attempt(static function () use (&$ready): void {
            AmpContext::async(static function () use (&$ready): void {
                AmpContext::delay(0.005);
                $ready = true;
            });

            Expect::eventually(static function () use (&$ready): bool {
                return $ready;
            })
                ->pollEvery(0.001)
                ->within(0.100)
                ->toBeTrue();
        });

        Expect::that($ready)->toBeTrue();
    }

    #[Test]
    public function registeredChildrenReceiveTheSameShorterTemporalDeadline(): void
    {
        $parentDeadline = null;
        $childDeadline = null;
        $nestedDeadline = null;
        $finished = false;
        $caught = self::capture(static function () use (&$parentDeadline, &$childDeadline, &$nestedDeadline, &$finished): void {
            self::attempt(static function () use (&$parentDeadline, &$childDeadline, &$nestedDeadline, &$finished): void {
                Expect::eventually(static function () use (&$parentDeadline, &$childDeadline, &$nestedDeadline, &$finished): bool {
                    $parentDeadline = ExpectationRuntime::enclosingDeadline();

                    return AmpContext::await(AmpContext::async(static function () use (&$childDeadline, &$nestedDeadline, &$finished): bool {
                        $childDeadline = ExpectationRuntime::enclosingDeadline();
                        Expect::eventually(static function () use (&$nestedDeadline): bool {
                            $nestedDeadline = ExpectationRuntime::enclosingDeadline();
                            AmpContext::delay(0.500);

                            return true;
                        })->within(0.500)->toBeTrue();
                        $finished = true;

                        return true;
                    }));
                })
                    ->retryOnException(\Exception::class)
                    ->within(0.015)
                    ->toBeTrue();
            });
        });

        $deadline = $caught instanceof \Throwable ? DeadlineExceededError::find($caught) : null;
        Expect::that($deadline)->toBeInstanceOf(DeadlineExceededError::class);
        Expect::that($deadline->testDeadline)->toBeFalse();
        Expect::that($parentDeadline)->not()->toBeNull();
        Expect::that($childDeadline)->toBe($parentDeadline);
        Expect::that($nestedDeadline)->toBe($parentDeadline);
        Expect::that($finished)->toBeFalse();
        Expect::that(ExpectationRuntime::enclosingDeadline())->toBeNull();
    }

    #[Test]
    public function cancellationStopsTheAwaitWithoutTerminatingItsProducer(): void
    {
        $producerFinished = false;
        $finishedWhenWaitStopped = null;
        $caught = null;

        self::attempt(static function () use (&$producerFinished, &$finishedWhenWaitStopped, &$caught): void {
            $producer = \Amp\async(static function () use (&$producerFinished): string {
                \Amp\delay(0.060);
                $producerFinished = true;

                return 'complete';
            });

            try {
                $caught = self::capture(static function () use ($producer): void {
                    ExpectationRuntime::withDeadline(
                        \Amp\now() + 0.010,
                        static function () use ($producer): void {
                            AmpContext::await($producer);
                        },
                    );
                });
                $finishedWhenWaitStopped = $producerFinished;
            } finally {
                Expect::that($producer->await())->toBe('complete');
            }
        });

        $deadline = $caught instanceof \Throwable ? DeadlineExceededError::find($caught) : null;
        Expect::that($deadline?->testDeadline)->toBeFalse();
        Expect::that($finishedWhenWaitStopped)->toBeFalse();
        Expect::that($producerFinished)->toBeTrue();
    }

    #[Test]
    public function anUnresolvedFutureStopsAtTheTestDeadline(): void
    {
        $caught = self::capture(static function (): void {
            self::attempt(static function (): void {
                $pending = new DeferredFuture();
                AmpContext::await($pending->getFuture());
            }, seconds: 0.010);
        });

        $deadline = $caught instanceof \Throwable ? DeadlineExceededError::find($caught) : null;
        Expect::that($deadline)->toBeInstanceOf(DeadlineExceededError::class);
        Expect::that($deadline->testDeadline)->toBeTrue();
    }

    #[Test]
    public function scopeEndWaitsForRegisteredChildCleanup(): void
    {
        $events = [];

        self::attempt(static function () use (&$events): void {
            $started = new DeferredFuture();
            AmpContext::async(static function () use ($started, &$events): void {
                try {
                    $events[] = 'child started';
                    $started->complete();
                    AmpContext::delay(10.000);
                } finally {
                    \Amp\delay(0.005);
                    $events[] = 'child cleanup';
                }
            });
            AmpContext::await($started->getFuture());
            $events[] = 'body ended';
        });
        $events[] = 'attempt returned';

        Expect::that($events)->toBe(['child started', 'body ended', 'child cleanup', 'attempt returned']);
    }

    #[Test]
    public function scopeEndInterruptsChildTemporalPolling(): void
    {
        $observations = 0;
        $cleanupCalls = 0;

        self::attempt(static function () use (&$observations, &$cleanupCalls): void {
            $started = new DeferredFuture();
            AmpContext::async(static function () use ($started, &$observations, &$cleanupCalls): void {
                try {
                    Expect::eventually(static function () use ($started, &$observations): bool {
                        if (++$observations === 1) {
                            $started->complete();
                        }

                        return false;
                    })
                        ->pollEvery(0.100)
                        ->within(0.500)
                        ->toBeTrue();
                } finally {
                    ++$cleanupCalls;
                }
            });
            AmpContext::await($started->getFuture());
        });

        Expect::that($observations)->toBe(1);
        Expect::that($cleanupCalls)->toBe(1);
    }

    #[Test]
    public function scopeCancellationIsNotRetriedAsAProbeFailure(): void
    {
        $observations = 0;
        $continued = false;

        self::attempt(static function () use (&$observations, &$continued): void {
            $started = new DeferredFuture();
            AmpContext::async(static function () use ($started, &$observations, &$continued): void {
                Expect::eventually(static function () use ($started, &$observations): bool {
                    if (++$observations === 1) {
                        $started->complete();
                    }

                    AmpContext::delay(10.000);

                    return true;
                })
                    ->retryOnException(\Exception::class)
                    ->within(0.500)
                    ->toBeTrue();
                $continued = true;
            });
            AmpContext::await($started->getFuture());
        });

        Expect::that($observations)->toBe(1);
        Expect::that($continued)->toBeFalse();
    }

    #[Test]
    public function scopeCancellationCannotPassAToThrowMatcher(): void
    {
        $continued = false;

        self::attempt(static function () use (&$continued): void {
            $started = new DeferredFuture();
            AmpContext::async(static function () use ($started, &$continued): void {
                Expect::that(static function () use ($started): void {
                    $started->complete();
                    AmpContext::delay(10.000);
                })->toThrow(\Throwable::class);
                $continued = true;
            });
            AmpContext::await($started->getFuture());
        });

        Expect::that($continued)->toBeFalse();
    }

    #[Test]
    public function temporalProbesPreserveCleanupErrorsThatWrapScopeCancellation(): void
    {
        $caught = self::capture(static function (): void {
            self::attempt(static function (): void {
                $started = new DeferredFuture();
                AmpContext::async(static function () use ($started): void {
                    Expect::eventually(static function () use ($started): bool {
                        $started->complete();

                        try {
                            AmpContext::delay(10.000);
                        } catch (CancelledException $cancelled) {
                            throw new \RuntimeException('Probe cleanup failed.', $cancelled->getCode(), previous: $cancelled);
                        }

                        return true;
                    })
                        ->retryOnException(\Exception::class)
                        ->within(0.500)
                        ->toBeTrue();
                });
                AmpContext::await($started->getFuture());
            });
        });

        Expect::that($caught)->toBeInstanceOf(\RuntimeException::class);
        Expect::that($caught->getMessage())->toBe('Probe cleanup failed.');
        Expect::that($caught->getPrevious())->toBeInstanceOf(CancelledException::class);
    }

    #[Test]
    public function suspendedMatcherCallbacksKeepSiblingAssertionsInTheCount(): void
    {
        $count = self::attempt(static function (): int {
            ExpectationCounter::reset();
            $entered = new DeferredFuture();
            $release = new DeferredFuture();
            AmpContext::async(static function () use ($entered, $release): void {
                AmpContext::await($entered->getFuture());
                Expect::that('sibling')->toBe('sibling');
                $release->complete();
            });

            Expect::eventually(static fn(): \Closure => static fn(): never => throw new \RuntimeException('The operation failed.'))
                ->within(0.100)
                ->toThrow(static function (\RuntimeException $error) use ($entered, $release): void {
                    $entered->complete();
                    AmpContext::await($release->getFuture());
                    Expect::that('callback')->toBe('callback');
                });

            return ExpectationCounter::count();
        });

        Expect::that($count)->toBe(2);
    }

    #[Test]
    public function scopeEndPreservesChildCleanupErrorsThatWrapCancellation(): void
    {
        $caught = self::capture(static function (): void {
            self::attempt(static function (): void {
                $started = new DeferredFuture();
                AmpContext::async(static function () use ($started): void {
                    $started->complete();

                    try {
                        AmpContext::delay(10.000);
                    } catch (CancelledException $cancelled) {
                        throw new \RuntimeException('Child cleanup failed.', $cancelled->getCode(), previous: $cancelled);
                    }
                });
                AmpContext::await($started->getFuture());
            });
        });

        Expect::that($caught)->toBeInstanceOf(\RuntimeException::class);
        Expect::that($caught->getMessage())->toBe('Child cleanup failed.');
    }

    #[Test]
    public function aHandledChildFailureDoesNotFailAgainAtScopeEnd(): void
    {
        self::attempt(static function (): void {
            $child = AmpContext::async(static fn(): never => throw new \RuntimeException('The child failed.'));

            Expect::that(static fn(): mixed => AmpContext::await($child))
                ->toThrow(\RuntimeException::class, message: 'The child failed.');
        });
    }

    #[Test]
    public function unregisteredFibersRequireExplicitCancellationPropagation(): void
    {
        self::attempt(static function (): void {
            $unregistered = \Amp\async(static fn(): Cancellation => AmpContext::cancellation());

            Expect::that(static function () use ($unregistered): void {
                $unregistered->await();
            })
                ->toThrow(AmpBridgeError::class, matching: '/AmpContext requires an active AmpPlugin attempt/');

            $cancellation = AmpContext::cancellation();
            $explicit = \Amp\async(static function () use ($cancellation): string {
                \Amp\delay(0.001, cancellation: $cancellation);

                return 'complete';
            });

            Expect::that(AmpContext::await($explicit))->toBe('complete');
        });
    }

    #[Test]
    public function closedAttemptsCancelRetainedTokensAndRemoveTheirTimers(): void
    {
        $plugin = new AmpPlugin();
        $before = EventLoop::getIdentifiers();
        $retained = self::attempt(static fn(): Cancellation => AmpContext::cancellation(), $plugin, 0.050);

        Expect::that($retained->isRequested())->toBeTrue();
        Expect::that(EventLoop::getIdentifiers())->toBe($before);

        self::attempt(static function () use ($retained): void {
            $fresh = AmpContext::cancellation();
            AmpContext::delay(0.070);
            $closed = self::capture(static fn() => $retained->throwIfRequested());

            Expect::that($fresh->isRequested())->toBeFalse();
            Expect::that($closed)->toBeInstanceOf(CancelledException::class);
            Expect::that(DeadlineExceededError::find($closed))->toBeNull();
        }, $plugin);

        Expect::that(EventLoop::getIdentifiers())->toBe($before);
    }

    #[Test]
    public function theFinalSynchronousObservationStillRunsAfterTheDeadline(): void
    {
        $calls = 0;

        $caught = self::capture(static function () use (&$calls): void {
            self::attempt(static function () use (&$calls): void {
                Expect::eventually(static function () use (&$calls): bool {
                    return ++$calls === 2;
                })
                    ->pollEvery(0.100)
                    ->within(0.010)
                    ->toBeTrue();
            });
        });

        Expect::that($caught)->toBeInstanceOf(ExpectationFailed::class);
        Expect::that($calls)->toBe(2);
    }

    #[Test]
    public function consistencyStartsItsPeriodAfterTheFirstAsyncObservation(): void
    {
        $calls = 0;
        $firstCompletedAt = null;
        $lastObservedAt = null;

        self::attempt(static function () use (&$calls, &$firstCompletedAt, &$lastObservedAt): void {
            Expect::consistently(static function () use (&$calls, &$firstCompletedAt, &$lastObservedAt): bool {
                if (++$calls === 1) {
                    AmpContext::delay(0.025);
                    $firstCompletedAt = \Amp\now();
                }

                $lastObservedAt = \Amp\now();

                return true;
            })
                ->pollEvery(0.005)
                ->for(0.015)
                ->toBeTrue();
        });

        Expect::that($calls)->toBeGreaterThan(1);
        Expect::that(($lastObservedAt ?? 0.0) - ($firstCompletedAt ?? 0.0))->toBeGreaterThanOrEqual(0.015);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private static function attempt(\Closure $operation, ?AmpPlugin $plugin = null, float $seconds = 1.000): mixed
    {
        $plugin ??= new AmpPlugin();

        return $plugin->runTestAttempt(static function () use ($operation, $plugin, $seconds): mixed {
            $deadline = \Amp\now() + $seconds;
            ExpectationRuntime::enterAttempt($deadline);

            try {
                $plugin->enterTestAttempt($deadline);

                try {
                    return $operation();
                } finally {
                    try {
                        $plugin->leaveTestBody();
                    } finally {
                        $plugin->leaveTestAttempt();
                    }
                }
            } finally {
                ExpectationRuntime::leaveAttempt();
            }
        });
    }

    /** @param \Closure(): void $operation */
    private static function capture(\Closure $operation): ?\Throwable
    {
        try {
            $operation();
        } catch (\Throwable $error) {
            return $error;
        }

        return null;
    }
}
