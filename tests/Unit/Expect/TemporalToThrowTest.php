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
            $eventually = Expect::calling(static function () use (&$calls) {
                ++$calls;
                if ($calls !== 1) {
                    throw new \RuntimeException('ready 42');
                }
            })->eventually()
                ->pollEvery(0.010)
                ->within(0.100);

            match ($constraint) {
                'type' => $eventually->toThrow(\RuntimeException::class),
                'pattern' => $eventually->toThrow(\RuntimeException::class, matching: '/^ready \d+$/'),
                'message' => $eventually->toThrow(\RuntimeException::class, message: 'ready 42'),
            };
        });

        Expect::value($calls)
            ->because('eventually() MUST retry until the returned callable satisfies toThrow()')
            ->toBe(2);
        Expect::value($clock->sleeps)
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
            Expect::calling(static function () use (&$calls, $failure) {
                ++$calls;
                throw $calls === 1 ? new \RuntimeException('ready') : $failure;
            })->eventually()
                ->pollEvery(0.010)
                ->within(0.100)
                ->toThrow($failure);
        });

        Expect::value($calls)->toBe(2);
        Expect::value($clock->sleeps)->toBe([0.010]);
    }

    #[Test]
    public function temporalToThrowRejectsAConstraintWithAThrowableCallbackBeforePolling(): void
    {
        $calls = 0;
        $eventually = Expect::calling(static function () use (&$calls) {
            ++$calls;
            throw new \RuntimeException('boom');
        })->eventually()->within(0.100);

        $detail = FailureProbe::detailOf(
            static fn() => $eventually->toThrow( // @phpstan-ignore greenlight.toThrow.callbackConstraint (deliberately invalid: tests runtime validation)
                static function (\RuntimeException $error): void {},
                message: 'boom',
            ),
        );

        Expect::value($detail->message)->toBe(
            'Do not specify matching: or message: when the throwable is a callback.',
        );
        Expect::value($calls)->toBe(0);
    }

    #[Test]
    public function temporalToThrowRejectsAConstraintWithAThrowableInstanceBeforePolling(): void
    {
        $calls = 0;
        $failure = new \RuntimeException('boom');
        $eventually = Expect::calling(static function () use (&$calls, $failure) {
            ++$calls;
            throw $failure;
        })->eventually()->within(0.100);

        $detail = FailureProbe::detailOf(
            static fn() => $eventually->toThrow( // @phpstan-ignore greenlight.toThrow.instanceConstraint (deliberately invalid: tests runtime validation)
                $failure,
                matching: '/boom/',
            ),
        );

        Expect::value($detail->message)->toBe(
            'Do not specify matching: or message: when the throwable argument is a Throwable instance.',
        );
        Expect::value($calls)->toBe(0);
    }

    #[Test]
    public function eventuallyRetriesWhenTheThrowableCallbackExpectationFails(): void
    {
        $clock = new FakePollingClock();
        $calls = 0;

        ExpectationRuntime::withClock($clock, static function () use (&$calls): void {
            Expect::calling(static fn() => (static fn() => throw new \RuntimeException('ready'))())->eventually()
                ->pollEvery(0.010)
                ->within(0.100)
                ->toThrow(
                    static function (\RuntimeException $error) use (&$calls): void {
                        ++$calls;
                        Expect::value($calls)->toBe(2);
                        Expect::value($error->getMessage())->toBe('ready');
                    },
                );
        });

        Expect::value($calls)->toBe(2);
        Expect::value($clock->sleeps)->toBe([0.010]);
    }

    #[Test]
    public function eventuallyReportsTheFinalThrowableCallbackFailure(): void
    {
        $clock = new FakePollingClock();

        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => Expect::calling(static fn() => (static fn() => throw new \RuntimeException('not ready'))())->eventually()
                ->pollEvery(0.010)
                ->within(0.020)
                ->toThrow(
                    static function (\RuntimeException $error): void {
                        Expect::value($error->getMessage())->toBe('ready');
                    },
                ),
        ));

        Expect::value($detail->message)->toContain(
            "Last failure: Expected 'not ready' to be 'ready'.",
        );
        Expect::value($detail->expected)->toBe("'ready'");
        Expect::value($detail->actual)->toBe("'not ready'");
        Expect::value($clock->sleeps)->toBe([0.010, 0.010]);
    }
}
