<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakePollingClock;

final class TemporalToThrowTest
{
    /**
     * @param 'type'|'pattern'|'message' $constraint
     */
    #[Test]
    #[DataSet('messageConstraints')]
    public function eventuallyRetriesUntilACallableThrowsTheExpectedException(string $constraint): void
    {
        $clock = new FakePollingClock();
        $calls = 0;

        ExpectationRuntime::withClock($clock, static function () use ($constraint, &$calls): void {
            $eventually = Expect::eventually(static function () use (&$calls): \Closure {
                ++$calls;

                return $calls === 1
                    ? static function (): void {}
                : static fn(): never => throw new \RuntimeException('ready 42');
            })
                ->pollEvery(0.010)
                ->within(0.100);

            match ($constraint) {
                'type' => $eventually->toThrow(\RuntimeException::class),
                'pattern' => $eventually->toThrow(\RuntimeException::class, matching: '/^ready \d+$/'),
                'message' => $eventually->toThrow(\RuntimeException::class, message: 'ready 42'),
            };
        });

        Expect::that($calls)
            ->because('eventually() MUST retry until the returned callable satisfies toThrow()')
            ->toBe(2);
        Expect::that($clock->sleeps)
            ->toBe([0.010]);
    }

    /**
     * @return iterable<string, array{'type'|'pattern'|'message'}>
     */
    public static function messageConstraints(): iterable
    {
        yield 'type only' => ['type'];
        yield 'message pattern' => ['pattern'];
        yield 'exact message' => ['message'];
    }

    #[Test]
    public function eventuallyRetriesUntilACallableThrowsTheExactThrowableInstance(): void
    {
        $clock = new FakePollingClock();
        $calls = 0;
        $failure = new \RuntimeException('ready');

        ExpectationRuntime::withClock($clock, static function () use (&$calls, $failure): void {
            Expect::eventually(static function () use (&$calls, $failure): \Closure {
                ++$calls;

                return $calls === 1
                    ? static fn() => throw new \RuntimeException('ready')
                    : static fn() => throw $failure;
            })
                ->pollEvery(0.010)
                ->within(0.100)
                ->toThrow($failure);
        });

        Expect::that($calls)->toBe(2);
        Expect::that($clock->sleeps)->toBe([0.010]);
    }

    #[Test]
    public function temporalToThrowRejectsAConstraintWithAThrowableCallbackBeforePolling(): void
    {
        $calls = 0;
        $eventually = Expect::eventually(static function () use (&$calls): \Closure {
            ++$calls;

            return static fn() => throw new \RuntimeException('boom');
        })->within(0.100);

        $detail = FailureProbe::detailOf(
            static function () use ($eventually): void {
                new \ReflectionMethod($eventually, 'toThrow')->invokeArgs(
                    $eventually,
                    [
                        'throwable' => static function (\RuntimeException $error): void {},
                        'message' => 'boom',
                    ],
                );
            },
        );

        Expect::that($detail->message)->toBe(
            'Do not specify matching: or message: when the throwable is a callback.',
        );
        Expect::that($calls)->toBe(0);
    }

    #[Test]
    public function temporalToThrowRejectsAConstraintWithAThrowableInstanceBeforePolling(): void
    {
        $calls = 0;
        $failure = new \RuntimeException('boom');
        $eventually = Expect::eventually(static function () use (&$calls, $failure): \Closure {
            ++$calls;

            return static fn() => throw $failure;
        })->within(0.100);

        $detail = FailureProbe::detailOf(
            static function () use ($eventually, $failure): void {
                new \ReflectionMethod($eventually, 'toThrow')->invokeArgs(
                    $eventually,
                    [
                        'throwable' => $failure,
                        'matching' => '/boom/',
                    ],
                );
            },
        );

        Expect::that($detail->message)->toBe(
            'Do not specify matching: or message: when the throwable argument is a Throwable instance.',
        );
        Expect::that($calls)->toBe(0);
    }

    #[Test]
    public function eventuallyRetriesWhenTheThrowableCallbackExpectationFails(): void
    {
        $clock = new FakePollingClock();
        $calls = 0;

        ExpectationRuntime::withClock($clock, static function () use (&$calls): void {
            Expect::eventually(static fn(): \Closure => static fn() => throw new \RuntimeException('ready'))
                ->pollEvery(0.010)
                ->within(0.100)
                ->toThrow(
                    static function (\RuntimeException $error) use (&$calls): void {
                        ++$calls;
                        Expect::that($calls)->toBe(2);
                        Expect::that($error->getMessage())->toBe('ready');
                    },
                );
        });

        Expect::that($calls)->toBe(2);
        Expect::that($clock->sleeps)->toBe([0.010]);
    }

    #[Test]
    public function eventuallyReportsTheFinalThrowableCallbackFailure(): void
    {
        $clock = new FakePollingClock();

        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => Expect::eventually(
                static fn(): \Closure => static fn() => throw new \RuntimeException('not ready'),
            )
                ->pollEvery(0.010)
                ->within(0.020)
                ->toThrow(
                    static function (\RuntimeException $error): void {
                        Expect::that($error->getMessage())->toBe('ready');
                    },
                ),
        ));

        Expect::that($detail->message)->toContain(
            "Last failure: Expected 'not ready' to be 'ready'.",
        );
        Expect::that($detail->expected)->toBe("'ready'");
        Expect::that($detail->actual)->toBe("'not ready'");
        Expect::that($clock->sleeps)->toBe([0.010, 0.010]);
    }
}
