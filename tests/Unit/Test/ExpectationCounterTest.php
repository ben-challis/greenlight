<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\ExpectationCounter;

final class ExpectationCounterTest
{
    #[Test]
    public function nestedSuppressionPreservesTheValueAndResumesCounting(): void
    {
        ExpectationCounter::reset();
        ExpectationCounter::increment();
        $observed = [];

        $value = ExpectationCounter::withoutCounting(
            static function () use (&$observed): string {
                ExpectationCounter::increment();
                $observed[] = ExpectationCounter::count();

                return ExpectationCounter::withoutCounting(
                    static function () use (&$observed): string {
                        ExpectationCounter::increment();
                        $observed[] = ExpectationCounter::count();

                        return 'result';
                    },
                );
            },
        );
        $afterSuppression = ExpectationCounter::count();
        ExpectationCounter::increment();
        $afterResume = ExpectationCounter::count();

        Expect::that($value)
            ->because('nested suppression MUST preserve the operation value')
            ->toBe('result');
        Expect::that($observed)
            ->because('nested suppression MUST keep the expectation count unchanged')
            ->toBe([1, 1]);
        Expect::that($afterSuppression)
            ->because('nested suppression MUST restore the earlier expectation count')
            ->toBe(1);
        Expect::that($afterResume)
            ->because('expectation counting MUST resume after nested suppression')
            ->toBe(2);
    }

    #[Test]
    public function suppressionIsRestoredWhenTheOperationThrows(): void
    {
        ExpectationCounter::reset();

        Expect::that(static fn(): mixed => ExpectationCounter::withoutCounting(
            static function (): never {
                ExpectationCounter::increment();

                throw new \RuntimeException('operation failed');
            },
        ))
            ->because('suppression MUST propagate an operation error')
            ->toThrow(\RuntimeException::class, message: 'operation failed');

        $afterThrow = ExpectationCounter::count();
        ExpectationCounter::increment();
        $afterResume = ExpectationCounter::count();

        Expect::that($afterThrow)
            ->because('suppression MUST restore the earlier count after an operation error')
            ->toBe(1);
        Expect::that($afterResume)
            ->because('expectation counting MUST resume after an operation error')
            ->toBe(2);
    }

    #[Test]
    public function suspendedSuppressionDoesNotHideAssertionsInOtherContexts(): void
    {
        ExpectationCounter::reset();
        $fiber = new \Fiber(static function (): void {
            ExpectationCounter::withoutCounting(static function (): void {
                Expect::that(true)->toBeTrue();
                \Fiber::suspend();
                Expect::that(true)->toBeTrue();
            });
            Expect::that(true)->toBeTrue();
        });

        $fiber->start();
        $afterSuspension = ExpectationCounter::count();
        Expect::that(true)->toBeTrue();
        $sibling = new \Fiber(static function (): void {
            Expect::that(true)->toBeTrue();
        });
        $sibling->start();
        $afterOtherContexts = ExpectationCounter::count();
        $fiber->resume();
        $afterResume = ExpectationCounter::count();

        Expect::that($afterSuspension)->toBe(0);
        Expect::that($afterOtherContexts)->toBe(2);
        Expect::that($afterResume)->toBe(3);
    }

    #[Test]
    public function mainSuppressionDoesNotApplyToChildFibers(): void
    {
        ExpectationCounter::reset();

        ExpectationCounter::withoutCounting(static function (): void {
            $fiber = new \Fiber(static function (): void {
                Expect::that(true)->toBeTrue();
            });
            $fiber->start();
            Expect::that(true)->toBeTrue();
        });
        $afterSuppression = ExpectationCounter::count();
        Expect::that(true)->toBeTrue();
        $afterResume = ExpectationCounter::count();

        Expect::that($afterSuppression)->toBe(1);
        Expect::that($afterResume)->toBe(2);
    }

    #[Test]
    public function nestedFiberSuppressionRestoresCountingAfterAnError(): void
    {
        ExpectationCounter::reset();
        $message = null;
        $fiber = new \Fiber(static function () use (&$message): void {
            try {
                ExpectationCounter::withoutCounting(static function (): void {
                    Expect::that(true)->toBeTrue();
                    ExpectationCounter::withoutCounting(static function (): never {
                        Expect::that(true)->toBeTrue();

                        throw new \RuntimeException('Operation failed.');
                    });
                });
            } catch (\RuntimeException $error) {
                $message = $error->getMessage();
            }

            Expect::that(true)->toBeTrue();
        });
        $fiber->start();
        $afterError = ExpectationCounter::count();

        Expect::that($message)->toBe('Operation failed.');
        Expect::that($afterError)->toBe(1);
    }

    #[Test]
    public function resetPreventsOldMainScopesFromRestoringSuppression(): void
    {
        ExpectationCounter::reset();

        ExpectationCounter::withoutCounting(static function (): void {
            ExpectationCounter::withoutCounting(static function (): void {
                ExpectationCounter::reset();
                ExpectationCounter::increment();
            });
            ExpectationCounter::increment();
        });
        ExpectationCounter::increment();
        $afterReset = ExpectationCounter::count();

        Expect::that($afterReset)->toBe(3);
    }

    #[Test]
    public function resetClearsSuppressionInSuspendedFibers(): void
    {
        ExpectationCounter::reset();
        $fiber = new \Fiber(static function (): void {
            ExpectationCounter::withoutCounting(static function (): void {
                ExpectationCounter::withoutCounting(static function (): void {
                    \Fiber::suspend();
                    ExpectationCounter::increment();
                });
                ExpectationCounter::increment();
            });
            ExpectationCounter::increment();
        });
        $fiber->start();
        ExpectationCounter::reset();
        $fiber->resume();
        $afterReset = ExpectationCounter::count();

        Expect::that($afterReset)->toBe(3);
    }
}
