<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\FrozenClock;
use Greenlight\Fixture\MutableClock;

final class ClockTest
{
    #[Test]
    public function frozenClockReturnsTheSameInstantOnEveryCall(): void
    {
        $clock = new FrozenClock();

        $first = $clock->now();
        \usleep(1000);

        Expect::that($clock->now())->toBe($first);
    }

    #[Test]
    public function frozenClockFreezesAtAGivenInstant(): void
    {
        $instant = new \DateTimeImmutable('2026-01-02 03:04:05.678900');

        Expect::that(new FrozenClock($instant)->now())->toBe($instant);
    }

    #[Test]
    public function frozenClockAtAcceptsStringsAndObjects(): void
    {
        $instant = new \DateTimeImmutable('2026-01-02 03:04:05');

        Expect::that(FrozenClock::at($instant)->now())->toBe($instant)
            ->and(FrozenClock::at('2026-01-02 03:04:05')->now()->format('Y-m-d H:i:s'))
            ->toBe('2026-01-02 03:04:05');
    }

    #[Test]
    public function mutableClockSetMovesTheClock(): void
    {
        $clock = new MutableClock(new \DateTimeImmutable('2026-01-01 00:00:00'));

        $clock->set('2026-06-15 12:30:00');

        Expect::that($clock->now()->format('Y-m-d H:i:s'))->toBe('2026-06-15 12:30:00');

        $instant = new \DateTimeImmutable('2026-07-01 08:00:00');
        $clock->set($instant);

        Expect::that($clock->now())->toBe($instant);
    }

    #[Test]
    public function mutableClockAdvancesByADateInterval(): void
    {
        $clock = new MutableClock(new \DateTimeImmutable('2026-01-01 00:00:00'));

        $clock->advance(new \DateInterval('P1DT2H'));

        Expect::that($clock->now()->format('Y-m-d H:i:s'))->toBe('2026-01-02 02:00:00');
    }

    #[Test]
    public function mutableClockAdvancesByWholeSeconds(): void
    {
        $clock = new MutableClock(new \DateTimeImmutable('2026-01-01 00:00:00'));

        $clock->advance(90);

        Expect::that($clock->now()->format('Y-m-d H:i:s'))->toBe('2026-01-01 00:01:30');
    }

    #[Test]
    public function mutableClockAdvancesByFractionalSeconds(): void
    {
        $clock = new MutableClock(new \DateTimeImmutable('2026-01-01 00:00:00.000000'));

        $clock->advance(1.5);

        Expect::that($clock->now()->format('Y-m-d H:i:s.u'))->toBe('2026-01-01 00:00:01.500000');

        $clock->advance(0.000250);

        Expect::that($clock->now()->format('Y-m-d H:i:s.u'))->toBe('2026-01-01 00:00:01.500250');
    }

    #[Test]
    public function advancingDoesNotMutatePreviouslyReturnedInstants(): void
    {
        $clock = new MutableClock(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $before = $clock->now();

        $clock->advance(3600);

        Expect::that($before->format('Y-m-d H:i:s'))->toBe('2026-01-01 00:00:00')
            ->and($clock->now())->not()->toBe($before);
    }
}
