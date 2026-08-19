<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Uses hrtime() for monotonic time and usleep() between polls.
 *
 * One native sleep call is at most one second. TemporalExpectation repeats
 * the call until it reaches the requested poll time.
 *
 * @internal
 */
final readonly class SystemPollingClock implements PollingClock
{
    private const float MAX_SLEEP_SECONDS = 1.0;

    /** @var \Closure(int): void */
    private \Closure $sleep;

    /** @param (\Closure(int): void)|null $sleep */
    public function __construct(?\Closure $sleep = null)
    {
        $this->sleep = $sleep ?? \usleep(...);
    }

    #[\Override]
    public function now(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        $microseconds = (int) \ceil(\min($seconds, self::MAX_SLEEP_SECONDS) * 1_000_000);
        ($this->sleep)(\max(1, $microseconds));
    }
}
