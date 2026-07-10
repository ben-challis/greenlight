<?php

declare(strict_types=1);

namespace Greenlight\Fixture;

/**
 * A clock the test moves by hand.
 *
 * now() returns the current instant, set() jumps to a specific time, and
 * advance() moves forward by a DateInterval or a number of seconds. Fractional
 * seconds are supported and microsecond precision is preserved. Instants
 * already returned are immutable and never change when the clock moves.
 */
final class MutableClock
{
    private \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $start = null)
    {
        $this->now = $start ?? new \DateTimeImmutable();
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable|string $time): void
    {
        $this->now = \is_string($time) ? new \DateTimeImmutable($time) : $time;
    }

    /**
     * @param \DateInterval|int|float $by an interval, or a number of seconds; fractions are kept as microseconds
     */
    public function advance(\DateInterval|int|float $by): void
    {
        if ($by instanceof \DateInterval) {
            $this->now = $this->now->add($by);

            return;
        }

        $microseconds = (int) \round($by * 1_000_000);

        $this->now = $this->now->modify(\sprintf('%+d microseconds', $microseconds));
    }
}
