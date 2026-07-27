<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

use Greenlight\Doubles\Fake;
use Greenlight\Expect\PollingClock;

final class StalledPollingClock implements PollingClock, Fake
{
    #[\Override]
    public function now(): float
    {
        return 0.0;
    }

    #[\Override]
    public function sleep(float $seconds): void {}
}
