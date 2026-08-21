<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\SystemWatchClock;
use Greenlight\Expect\Expect;

final class SystemWatchClockTest
{
    #[Test]
    public function nowUsesTheMonotonicSystemClock(): void
    {
        $before = \hrtime(true) / 1_000_000_000;
        $now = new SystemWatchClock()->now();
        $after = \hrtime(true) / 1_000_000_000;

        Expect::that($now)
            ->because('watch debounce timing MUST use the monotonic system clock')
            ->toBeGreaterThanOrEqual($before)
            ->toBeLessThanOrEqual($after);
    }

    #[Test]
    #[DataSet('nonpositiveDurations')]
    public function nonpositiveSleepDoesNotCallTheNativeSleeper(float $seconds): void
    {
        $microseconds = [];
        $clock = new SystemWatchClock(static function (int $duration) use (&$microseconds): void {
            $microseconds[] = $duration;
        });

        $clock->sleep($seconds);

        Expect::that($microseconds)
            ->because('a nonpositive delay MUST return before calling the native sleeper')
            ->toBe([]);
    }

    /** @return iterable<string, array{float}> */
    public static function nonpositiveDurations(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.001];
    }

    /** @param list<positive-int> $expectedMicroseconds */
    #[Test]
    #[DataSet('positiveDurations')]
    public function positiveSleepUsesPortableNativeChunks(float $seconds, array $expectedMicroseconds): void
    {
        $microseconds = [];
        $clock = new SystemWatchClock(static function (int $duration) use (&$microseconds): void {
            $microseconds[] = $duration;
        });

        $clock->sleep($seconds);

        Expect::that($microseconds)
            ->because('each native sleep call MUST be between one microsecond and one second')
            ->toBe($expectedMicroseconds);
    }

    /** @return iterable<string, array{float, list<positive-int>}> */
    public static function positiveDurations(): iterable
    {
        yield 'sub-microsecond' => [0.000_000_1, [1]];
        yield 'fractional microsecond rounds up' => [0.000_001_1, [2]];
        yield 'watch poll interval' => [0.1, [100_000]];
        yield 'one second' => [1.0, [1_000_000]];
        yield 'multiple seconds' => [2.25, [1_000_000, 1_000_000, 250_000]];
    }

    #[Test]
    #[DataSet('nonfiniteDurations')]
    public function nonfiniteSleepIsRejected(float $seconds): void
    {
        $clock = new SystemWatchClock(static function (int $microseconds): void {});

        Expect::that(static fn() => $clock->sleep($seconds))
            ->toThrow(\InvalidArgumentException::class, '/^The sleep duration must be finite\.$/D');
    }

    /** @return iterable<string, array{float}> */
    public static function nonfiniteDurations(): iterable
    {
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'not a number' => [\NAN];
    }
}
