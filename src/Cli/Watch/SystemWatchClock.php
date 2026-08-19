<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * Uses hrtime() for monotonic time and usleep() between watch polls.
 *
 * One native sleep call is at most one second. Longer delays use multiple
 * calls because some operating systems do not support larger usleep() values.
 *
 * @internal
 */
final readonly class SystemWatchClock implements WatchClock
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
        if (!\is_finite($seconds)) {
            throw new \InvalidArgumentException('The sleep duration must be finite.');
        }

        while ($seconds > 0.0) {
            $chunk = \min($seconds, self::MAX_SLEEP_SECONDS);
            $microseconds = (int) \ceil($chunk * 1_000_000);
            ($this->sleep)(\max(1, $microseconds));
            $seconds -= $chunk;
        }
    }
}
