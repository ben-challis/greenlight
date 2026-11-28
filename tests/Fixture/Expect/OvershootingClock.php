<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

use Greenlight\Doubles\Fake;
use Greenlight\Expect\Clock;

/**
 * Models a real sleep, which ends after the requested time.
 */
final class OvershootingClock implements Clock, Fake
{
    public float $time = 0.0;

    public function __construct(private readonly float $overshootSeconds) {}

    #[\Override]
    public function now(): float
    {
        return $this->time;
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        $this->time += $seconds + $this->overshootSeconds;
    }
}
