<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakeClock;

final class CallExpectationTest
{
    #[Test]
    public function aCallableValueIsNeverInvoked(): void
    {
        $calls = 0;
        $callback = static function () use (&$calls): int {
            return ++$calls;
        };

        Expect::value($callback)->toBeCallable()->toBe($callback);
        Expect::value($calls)->toBe(0);
    }

    #[Test]
    public function projectionIsLazyAndImmediateMatchersShareOneInvocation(): void
    {
        $calls = 0;
        $call = Expect::calling(static function () use (&$calls): int {
            return ++$calls;
        });
        $value = $call->returnValue();

        Expect::value($calls)->toBe(0);
        $value->toBeInt()->toBe(1);
        $call->toReturn(1)->not()->toThrow();
        Expect::value($calls)->toBe(1);
    }

    #[Test]
    public function nullReturnValuesAreCapturedOnce(): void
    {
        $calls = 0;
        $call = Expect::calling(static function () use (&$calls): void {
            ++$calls;
        });

        $call->toReturn(null)->toReturn(null);
        Expect::value($calls)->toBe(1);
    }

    #[Test]
    public function thrownErrorsAreCapturedOnceAndKeepTheirIdentity(): void
    {
        $calls = 0;
        $error = new \Error('broken');
        $call = Expect::calling(static function () use (&$calls, $error): never {
            ++$calls;

            throw $error;
        });

        $call->toThrow()->toThrow($error);
        Expect::calling(static fn() => $call->returnValue()->toBeNull())->toThrow($error);
        Expect::value($calls)->toBe(1);
    }

    #[Test]
    public function temporalCallMatchersRetainTheMatchedOutcome(): void
    {
        $calls = 0;
        $error = new \RuntimeException('ready');
        ExpectationRuntime::withClock(new FakeClock(), static function () use (&$calls, $error): void {
            Expect::calling(static function () use (&$calls, $error): void {
                if (++$calls === 2) {
                    throw $error;
                }
            })->eventually()->within(0.1)->toThrow($error)->toThrow($error);
        });

        Expect::value($calls)->toBe(2);
    }

    #[Test]
    public function temporalReturnMatchersRetainTheMatchedValue(): void
    {
        $calls = 0;
        ExpectationRuntime::withClock(new FakeClock(), static function () use (&$calls): void {
            Expect::calling(static function () use (&$calls): int {
                return ++$calls;
            })->returnValue()->eventually()->within(0.1)->toBe(2)->toBeInt();
        });

        Expect::value($calls)->toBe(2);
    }

    #[Test]
    public function modifiersSurviveProjectionAndTimeControls(): void
    {
        ExpectationRuntime::withClock(new FakeClock(), static function (): void {
            Expect::calling(static fn(): int => 2)
                ->not()->because('the value must differ')
                ->returnValue()->eventually()->within(0.1)->toBe(1)->toBe(2);

            Expect::calling(static function (): void {})
                ->not()->eventually()->within(0.1)->toThrow()->toReturn(null);
        });
    }

    #[Test]
    public function temporalReturnEqualityUsesTheDirectCall(): void
    {
        $calls = 0;
        ExpectationRuntime::withClock(new FakeClock(), static function () use (&$calls): void {
            Expect::calling(static function () use (&$calls): int {
                return ++$calls;
            })->eventually()->within(0.1)->toReturn(2)->toReturn(2);
        });
        Expect::value($calls)->toBe(2);
    }
}
