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
}
