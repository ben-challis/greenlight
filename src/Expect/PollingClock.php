<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Monotonic time and sleeping used by temporal expectations.
 *
 * @internal
 */
interface PollingClock
{
    public function now(): float;

    public function sleep(float $seconds): void;
}
