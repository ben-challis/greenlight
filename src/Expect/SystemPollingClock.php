<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * System monotonic clock for production polling.
 *
 * @internal
 */
final readonly class SystemPollingClock implements PollingClock
{
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

        \usleep(\max(1, (int) \ceil($seconds * 1_000_000)));
    }
}
