<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Provides monotonic time and sleeping for polling expectations.
 *
 * @internal
 */
interface PollingClock
{
    public function now(): float;

    public function sleep(float $seconds): void;
}
