<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\SystemWatchClock;
use Greenlight\Expect\Expect;

final readonly class SystemWatchClockTest
{
    #[Test]
    public function nowUsesTheMonotonicSystemClock(): void
    {
        $before = \hrtime(true) / 1_000_000_000;
        $now = new SystemWatchClock()->now();
        $after = \hrtime(true) / 1_000_000_000;

        Expect::that($now)
            ->because('watch debounce timing MUST use the monotonic system clock')
            ->toBeGreaterThanOrEqual($before)
            ->toBeLessThanOrEqual($after);
    }
}
