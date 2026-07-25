<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\TemporalRetry;

use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Expect\Expect;

final class TemporalRetryTest
{
    public static int $attempts = 0;

    #[Test]
    #[Retry(times: 1)]
    #[Timeout(seconds: 0.100)]
    public function receivesAFreshTemporalDeadlineOnRetry(): void
    {
        ++self::$attempts;

        Expect::eventually(static fn(): int => self::$attempts)
            ->pollEvery(0.001)
            ->within(0.010)
            ->toBe(2);
    }
}
