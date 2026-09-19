<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Supplies monotonic time and delays to the poll loop.
 *
 * @internal
 */
interface PollingClock
{
    /** Returns monotonic time in seconds. */
    public function now(): float;

    /**
     * Waits until at least the requested duration has elapsed on this clock.
     * A nonpositive duration returns without delay.
     */
    public function sleep(float $seconds): void;
}
