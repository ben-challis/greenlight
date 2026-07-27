<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\SystemPollingClock;

final class SystemPollingClockTest
{
    #[Test]
    public function negativeSleepReturnsControlToTheCaller(): void
    {
        $reachedAfterSleep = false;

        new SystemPollingClock()->sleep(-0.001);
        $reachedAfterSleep = true;

        Expect::that($reachedAfterSleep)
            ->because('a negative sleep returns before calling usleep')
            ->toBeTrue();
    }
}
