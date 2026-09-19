<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\SystemClock;

use function Greenlight\expect;

final class SystemClockTest
{
    #[Test]
    #[DataSet('nonpositiveDurations')]
    public function nonpositiveSleepDoesNotCallTheNativeSleeper(float $seconds): void
    {
        $microseconds = [];
        $clock = new SystemClock(static function (int $duration) use (&$microseconds): void {
            $microseconds[] = $duration;
        });

        $clock->sleep($seconds);

        expect($microseconds)
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
    public function positiveSleepWaitsForTheFullDuration(float $seconds, array $expectedMicroseconds): void
    {
        $microseconds = [];
        $time = 0.0;
        $clock = new SystemClock(
            static function (int $duration) use (&$microseconds, &$time): void {
                $microseconds[] = $duration;
                $time += $duration / 1_000_000;
            },
            static function () use (&$time): float {
                return $time;
            },
        );

        $clock->sleep($seconds);

        expect($microseconds)
            ->because('native sleep calls cover the full delay in portable chunks')
            ->toBe($expectedMicroseconds);
        expect($clock->now())->toBeGreaterThanOrEqual($seconds);
    }

    /** @return iterable<string, array{float, list<positive-int>}> */
    public static function positiveDurations(): iterable
    {
        yield 'sub-microsecond' => [0.000_000_1, [1]];
        yield 'fractional microsecond rounds up' => [0.000_001_1, [2]];
        yield 'regular poll interval' => [0.025, [25_000]];
        yield 'one second' => [1.0, [1_000_000]];
        yield 'multiple seconds' => [2.0, [1_000_000, 1_000_000]];
        yield 'partial final chunk' => [2.25, [1_000_000, 1_000_000, 250_000]];
    }

    #[Test]
    public function anEarlyNativeReturnWaitsForTheRemainingDuration(): void
    {
        $time = 10.0;
        $microseconds = [];
        $clock = new SystemClock(
            static function (int $duration) use (&$time, &$microseconds): void {
                $microseconds[] = $duration;
                $time += \count($microseconds) === 1 ? 0.25 : $duration / 1_000_000;
            },
            static function () use (&$time): float {
                return $time;
            },
        );

        $clock->sleep(1.0);

        expect($microseconds)->toBe([1_000_000, 750_000]);
        expect($clock->now())->toBe(11.0);
    }

    #[Test]
    public function anOversleepDoesNotCauseAnotherNativeCall(): void
    {
        $time = 0.0;
        $microseconds = [];
        $clock = new SystemClock(
            static function (int $duration) use (&$time, &$microseconds): void {
                $microseconds[] = $duration;
                $time += 3.0;
            },
            static function () use (&$time): float {
                return $time;
            },
        );

        $clock->sleep(2.0);

        expect($microseconds)->toBe([1_000_000]);
        expect($clock->now())->toBe(3.0);
    }
}
