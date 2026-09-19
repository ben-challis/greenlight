<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Uses hrtime() for monotonic time and usleep() between polls.
 *
 * Repeats native sleep calls of at most one second until the requested
 * duration has elapsed.
 *
 * @internal
 */
final readonly class SystemPollingClock implements PollingClock
{
    private const float MAX_SLEEP_SECONDS = 1.0;

    /** @var \Closure(int): void */
    private \Closure $sleep;

    /** @var \Closure(): float */
    private \Closure $now;

    /**
     * @param (\Closure(int): void)|null $sleep
     * @param (\Closure(): float)|null $now
     */
    public function __construct(?\Closure $sleep = null, ?\Closure $now = null)
    {
        $this->sleep = $sleep ?? \usleep(...);
        $this->now = $now ?? static fn(): float => \hrtime(true) / 1_000_000_000;
    }

    #[\Override]
    public function now(): float
    {
        return ($this->now)();
    }

    /**
     * Waits for the full duration, even if a native sleep call returns early.
     */
    #[\Override]
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        $started = $this->now();
        $previous = $started;
        $remaining = $seconds;
        $stalled = 0;

        do {
            $microseconds = (int) \ceil(\min($remaining, self::MAX_SLEEP_SECONDS) * 1_000_000);
            ($this->sleep)(\max(1, $microseconds));
            $current = $this->now();

            if ($current <= $previous) {
                ++$stalled;

                if ($stalled >= 10_000) {
                    throw new \LogicException('The polling clock did not advance during sleep.');
                }
            } else {
                $stalled = 0;
            }

            $previous = $current;
            $remaining = $seconds - ($current - $started);
        } while ($remaining > 0.0);
    }
}
