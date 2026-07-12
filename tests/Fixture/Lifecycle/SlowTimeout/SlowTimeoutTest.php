<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\SlowTimeout;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;

final class SlowTimeoutTest
{
    #[Test]
    #[Timeout(seconds: 0.01)]
    public function tooSlow(): void
    {
        \usleep(25_000);
    }
}
