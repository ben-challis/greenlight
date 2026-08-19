<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\SystemPollingClock;

final class SystemPollingClockTest
{
    #[Test]
    #[DataSet('nonpositiveDurations')]
    public function nonpositiveSleepDoesNotCallTheNativeSleeper(float $seconds): void
    {
        $microseconds = [];
        $clock = new SystemPollingClock(static function (int $duration) use (&$microseconds): void {
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

    #[Test]
    #[DataSet('positiveDurations')]
    public function positiveSleepUsesAPortableNativeChunk(float $seconds, int $expectedMicroseconds): void
    {
        $microseconds = [];
        $clock = new SystemPollingClock(static function (int $duration) use (&$microseconds): void {
            $microseconds[] = $duration;
        });

        $clock->sleep($seconds);

        Expect::that($microseconds)
            ->because('one native sleep call MUST be between one microsecond and one second')
            ->toBe([$expectedMicroseconds]);
    }

    /** @return iterable<string, array{float, positive-int}> */
    public static function positiveDurations(): iterable
    {
        yield 'sub-microsecond' => [0.000_000_1, 1];
        yield 'fractional microsecond rounds up' => [0.000_001_1, 2];
        yield 'regular poll interval' => [0.025, 25_000];
        yield 'one second' => [1.0, 1_000_000];
        yield 'multiple seconds' => [2.0, 1_000_000];
        yield 'largest finite duration' => [\PHP_FLOAT_MAX, 1_000_000];
    }
}
