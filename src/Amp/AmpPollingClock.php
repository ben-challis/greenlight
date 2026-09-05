<?php

declare(strict_types=1);

namespace Greenlight\Amp;

use Greenlight\Expect\PollingClock;

/**
 * Supplies monotonic time and yields to the application's Revolt scheduler.
 *
 * @internal
 */
final readonly class AmpPollingClock implements PollingClock
{
    #[\Override]
    public function now(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        if ($seconds > 0.0) {
            $cancellation = AmpContext::pollingCancellation();
            $cancellation?->throwIfRequested();
            \Amp\delay($seconds, cancellation: $cancellation);
        }
    }
}
