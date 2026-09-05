<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Test\DeadlineExceededError;
use Greenlight\Tests\Fixture\Expect\FakePollingClock;

final class TemporalCancellationTest
{
    #[Test]
    public function eventuallyDoesNotRetryTestDeadlineCancellation(): void
    {
        $clock = new FakePollingClock();
        $deadline = DeadlineExceededError::forTest();
        $calls = 0;

        $caught = $this->capture(static function () use ($clock, $deadline, &$calls): void {
            ExpectationRuntime::withClock($clock, static function () use ($deadline, &$calls): void {
                Expect::eventually(static function () use ($deadline, &$calls): never {
                    ++$calls;

                    throw $deadline;
                })
                    ->retryOnException(\Exception::class)
                    ->within(1.000)
                    ->toBeTrue();
            });
        });

        Expect::that($caught)->toBe($deadline);
        Expect::that($calls)->toBe(1);
        Expect::that($clock->sleeps)->toBe([]);
        Expect::that(ExpectationRuntime::enclosingDeadline())->toBeNull();
    }

    #[Test]
    public function eventuallyFindsTemporalCancellationInNestedCauses(): void
    {
        $clock = new FakePollingClock();
        $deadline = DeadlineExceededError::forTemporal();
        $wrapped = new \RuntimeException(
            'The operation failed.',
            previous: new \RuntimeException('The wait was canceled.', previous: $deadline),
        );
        $calls = 0;

        $caught = $this->capture(static function () use ($clock, $wrapped, &$calls): void {
            ExpectationRuntime::withClock($clock, static function () use ($wrapped, &$calls): void {
                Expect::eventually(static function () use ($wrapped, &$calls): never {
                    ++$calls;

                    throw $wrapped;
                })
                    ->retryOnException(\RuntimeException::class)
                    ->within(1.000)
                    ->toBeTrue();
            });
        });

        Expect::that($caught)->toBe($wrapped);
        Expect::that($calls)->toBe(1);
        Expect::that($clock->sleeps)->toBe([]);
        Expect::that(ExpectationRuntime::enclosingDeadline())->toBeNull();
    }

    #[Test]
    public function consistentlyPreservesTheDeadlineCause(): void
    {
        $clock = new FakePollingClock();
        $deadline = DeadlineExceededError::forTest();
        $wrapped = new \RuntimeException('The wait was canceled.', previous: $deadline);

        $caught = $this->capture(static function () use ($clock, $wrapped): void {
            ExpectationRuntime::withClock($clock, static function () use ($wrapped): void {
                Expect::consistently(static fn(): never => throw $wrapped)
                    ->for(1.000)
                    ->toBeTrue();
            });
        });

        Expect::that($caught)->toBe($wrapped);
        Expect::that($clock->sleeps)->toBe([]);
        Expect::that(ExpectationRuntime::enclosingDeadline())->toBeNull();
    }

    #[Test]
    public function toThrowDoesNotAcceptDeadlineCancellation(): void
    {
        $deadline = DeadlineExceededError::forTest();
        $wrapped = new \RuntimeException('The wait was canceled.', previous: $deadline);

        $caught = $this->capture(static function () use ($wrapped): void {
            Expect::that(static fn(): never => throw $wrapped)->toThrow(\Throwable::class);
        });

        Expect::that($caught)->toBe($wrapped);
    }

    #[Test]
    public function temporalToThrowDoesNotAcceptDeadlineCancellation(): void
    {
        $clock = new FakePollingClock();
        $deadline = DeadlineExceededError::forTemporal();
        $wrapped = new \RuntimeException('The wait was canceled.', previous: $deadline);

        $caught = $this->capture(static function () use ($clock, $wrapped): void {
            ExpectationRuntime::withClock($clock, static function () use ($wrapped): void {
                Expect::eventually(static fn(): \Closure => static fn(): never => throw $wrapped)
                    ->retryOnException(\Exception::class)
                    ->within(1.000)
                    ->toThrow(\Throwable::class);
            });
        });

        Expect::that($caught)->toBe($wrapped);
        Expect::that($clock->sleeps)->toBe([]);
        Expect::that(ExpectationRuntime::enclosingDeadline())->toBeNull();
    }

    #[Test]
    public function matcherCallbacksPropagateDeadlineCancellation(): void
    {
        $clock = new FakePollingClock();
        $deadline = DeadlineExceededError::forTemporal();
        $calls = 0;

        $caught = $this->capture(static function () use ($clock, $deadline, &$calls): void {
            ExpectationRuntime::withClock($clock, static function () use ($deadline, &$calls): void {
                Expect::eventually(static fn(): \Closure => static fn(): never => throw new \RuntimeException('The operation failed.'))
                    ->retryOnException(\Exception::class)
                    ->within(1.000)
                    ->toThrow(static function (\RuntimeException $error) use ($deadline, &$calls): void {
                        ++$calls;

                        throw $deadline;
                    });
            });
        });

        Expect::that($caught)->toBe($deadline);
        Expect::that($calls)->toBe(1);
        Expect::that($clock->sleeps)->toBe([]);
        Expect::that(ExpectationRuntime::enclosingDeadline())->toBeNull();
    }

    /** @param \Closure(): void $operation */
    private function capture(\Closure $operation): ?\Throwable
    {
        try {
            $operation();
        } catch (\Throwable $error) {
            return $error;
        }

        return null;
    }
}
