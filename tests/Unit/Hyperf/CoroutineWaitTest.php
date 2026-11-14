<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Hyperf;

use Greenlight\Attribute\Test;
use Greenlight\Hyperf\CoroutineWait;

use function Greenlight\expect;

final readonly class CoroutineWaitTest
{
    #[Test]
    public function aBlockedCoroutineStopsTheWaitAtTheDeadline(): void
    {
        $now = 100.0;
        $timeouts = [];

        $finished = CoroutineWait::await(
            static function (float $timeout) use (&$now, &$timeouts): bool {
                $timeouts[] = $timeout;
                $now += $timeout;

                return false;
            },
            static fn(): float => 102.5,
            static function () use (&$now): float {
                return $now;
            },
            static fn(): bool => true,
        );

        expect($finished)->toBeFalse();
        expect($timeouts)->toBe([2.5]);
    }

    #[Test]
    public function aWaitThatEndsEarlyWaitsAgainForTheRemainingTime(): void
    {
        $now = 0.0;
        $timeouts = [];

        $finished = CoroutineWait::await(
            static function (float $timeout) use (&$now, &$timeouts): bool {
                $timeouts[] = $timeout;
                $now += 0.75;

                return false;
            },
            static fn(): float => 1.0,
            static function () use (&$now): float {
                return $now;
            },
            static fn(): bool => true,
        );

        expect($finished)->toBeFalse();
        expect($timeouts)->toBe([1.0, 0.25]);
    }

    #[Test]
    public function aFinishedCoroutineEndsTheWait(): void
    {
        $finished = CoroutineWait::await(
            static fn(float $timeout): bool => true,
            static fn(): float => 1.0,
            static fn(): float => 0.0,
            static fn(): bool => true,
        );

        expect($finished)->toBeTrue();
    }

    #[Test]
    public function aPassedDeadlineStillChecksForCompletion(): void
    {
        $timeouts = [];

        $finished = CoroutineWait::await(
            static function (float $timeout) use (&$timeouts): bool {
                $timeouts[] = $timeout;

                return true;
            },
            static fn(): float => 1.0,
            static fn(): float => 5.0,
            static fn(): bool => true,
        );

        expect($finished)->toBeTrue();
        expect($timeouts)->toBe([CoroutineWait::MINIMUM_WAIT_SECONDS]);
    }

    #[Test]
    public function readsADeadlineThatTheCoroutineSetsAfterTheFirstWait(): void
    {
        $now = 0.0;
        $deadline = null;
        $timeouts = [];

        $finished = CoroutineWait::await(
            static function (float $timeout) use (&$now, &$deadline, &$timeouts): bool {
                $timeouts[] = $timeout;
                $deadline ??= 0.5;
                $now += $timeout;

                return false;
            },
            static function () use (&$deadline): ?float {
                return $deadline;
            },
            static function () use (&$now): float {
                return $now;
            },
            static fn(): bool => true,
        );

        expect($finished)->toBeFalse();
        expect($timeouts)->toBe([CoroutineWait::FIRST_WAIT_SECONDS, 0.4]);
    }

    #[Test]
    public function withoutADeadlineTheWaitHasNoLimitAfterOneWaitInTheAttempt(): void
    {
        $timeouts = [];

        $finished = CoroutineWait::await(
            static function (float $timeout) use (&$timeouts): bool {
                $timeouts[] = $timeout;

                return $timeout < 0.0;
            },
            static fn(): ?float => null,
            static fn(): float => 0.0,
            static fn(): bool => true,
        );

        expect($finished)->toBeTrue();
        expect($timeouts)->toBe([CoroutineWait::FIRST_WAIT_SECONDS, -1.0]);
    }

    #[Test]
    public function aSlowSetupKeepsTheWaitBoundedUntilTheAttemptSetsItsDeadline(): void
    {
        $now = 0.0;
        $pops = 0;
        $started = false;
        $deadline = null;
        $timeouts = [];

        $finished = CoroutineWait::await(
            static function (float $timeout) use (&$now, &$pops, &$started, &$deadline, &$timeouts): bool {
                $timeouts[] = $timeout;
                $now += $timeout;
                ++$pops;

                if ($pops === 3) {
                    $started = true;
                    $deadline = $now + 0.5;
                }

                return false;
            },
            static function () use (&$deadline): ?float {
                return $deadline;
            },
            static function () use (&$now): float {
                return $now;
            },
            static function () use (&$started): bool {
                return $started;
            },
        );

        expect($finished)->toBeFalse();
        expect($timeouts)->toBe([
            CoroutineWait::FIRST_WAIT_SECONDS,
            CoroutineWait::FIRST_WAIT_SECONDS,
            CoroutineWait::FIRST_WAIT_SECONDS,
            0.5,
        ]);
    }
}
