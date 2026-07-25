<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Supplies monotonic time and delays to the polling loop.
 *
 * @internal
 */
interface PollingClock
{
    public function now(): float;

    public function sleep(float $seconds): void;
}
