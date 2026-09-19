<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakeClock;

use function Greenlight\expect;

final class TemporalToThrowTest
{
    /**
     * @param 'type'|'pattern'|'message' $constraint
     */
    #[Test]
    #[DataSet('messageConstraints')]
    public function eventuallyRetriesUntilACallableThrowsTheExpectedException(string $constraint): void
    {
        $clock = new FakeClock();
        $calls = 0;

        ExpectationRuntime::withClock($clock, static function () use ($constraint, &$calls): void {
            $eventually = expect()->calling(static function () use (&$calls) {
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

        expect($calls)
            ->because('eventually() MUST retry until the returned callable satisfies toThrow()')
            ->toBe(2);
        expect($clock->sleeps)
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
        $clock = new FakeClock();
        $calls = 0;
        $failure = new \RuntimeException('ready');

        ExpectationRuntime::withClock($clock, static function () use (&$calls, $failure): void {
            expect()->calling(static function () use (&$calls, $failure) {
                ++$calls;
                throw $calls === 1 ? new \RuntimeException('ready') : $failure;
            })->eventually()
                ->pollEvery(0.010)
                ->within(0.100)
                ->toThrow($failure);
        });

        expect($calls)->toBe(2);
        expect($clock->sleeps)->toBe([0.010]);
    }

    #[Test]
    public function temporalToThrowRejectsAConstraintWithAThrowableCallbackBeforePolling(): void
    {
        $calls = 0;
        $eventually = expect()->calling(static function () use (&$calls) {
            ++$calls;
            throw new \RuntimeException('boom');
        })->eventually()->within(0.100);

        $detail = FailureProbe::detailOf(
            static fn() => $eventually->toThrow( // @phpstan-ignore greenlight.toThrow.callbackConstraint (deliberately invalid: tests runtime validation)
                static function (\RuntimeException $error): void {},
                message: 'boom',
            ),
        );

        expect($detail->message)->toBe(
            'Do not specify matching: or message: when the throwable is a callback.',
        );
        expect($calls)->toBe(0);
    }

    #[Test]
    public function temporalToThrowRejectsAConstraintWithAThrowableInstanceBeforePolling(): void
    {
        $calls = 0;
        $failure = new \RuntimeException('boom');
        $eventually = expect()->calling(static function () use (&$calls, $failure) {
            ++$calls;
            throw $failure;
        })->eventually()->within(0.100);

        $detail = FailureProbe::detailOf(
            static fn() => $eventually->toThrow( // @phpstan-ignore greenlight.toThrow.instanceConstraint (deliberately invalid: tests runtime validation)
                $failure,
                matching: '/boom/',
            ),
        );

        expect($detail->message)->toBe(
            'Do not specify matching: or message: when the throwable argument is a Throwable instance.',
        );
        expect($calls)->toBe(0);
    }

    #[Test]
    public function eventuallyRetriesWhenTheThrowableCallbackExpectationFails(): void
    {
        $clock = new FakeClock();
        $calls = 0;

        ExpectationRuntime::withClock($clock, static function () use (&$calls): void {
            expect()->calling(static fn() => (static fn() => throw new \RuntimeException('ready'))())->eventually()
                ->pollEvery(0.010)
                ->within(0.100)
                ->toThrow(
                    static function (\RuntimeException $error) use (&$calls): void {
                        ++$calls;
                        expect($calls)->toBe(2);
                        expect($error->getMessage())->toBe('ready');
                    },
                );
        });

        expect($calls)->toBe(2);
        expect($clock->sleeps)->toBe([0.010]);
    }

    #[Test]
    public function eventuallyReportsTheFinalThrowableCallbackFailure(): void
    {
        $clock = new FakeClock();

        $detail = FailureProbe::detailOf(static fn() => ExpectationRuntime::withClock(
            $clock,
            static fn() => expect()->calling(static fn() => (static fn() => throw new \RuntimeException('not ready'))())->eventually()
                ->pollEvery(0.010)
                ->within(0.020)
                ->toThrow(
                    static function (\RuntimeException $error): void {
                        expect($error->getMessage())->toBe('ready');
                    },
                ),
        ));

        expect($detail->message)->toContain(
            "Last failure: Expected 'not ready' to be 'ready'.",
        );
        expect($detail->expected)->toBe("'ready'");
        expect($detail->actual)->toBe("'not ready'");
        expect($clock->sleeps)->toBe([0.010, 0.010]);
    }
}
