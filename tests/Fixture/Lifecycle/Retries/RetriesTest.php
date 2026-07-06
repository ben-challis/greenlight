<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Retries;

use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Test;

final class RetriesTest
{
    public static int $attempts = 0;

    #[Test]
    #[Retry(times: 2)]
    public function flaky(): void
    {
        ++self::$attempts;

        if (self::$attempts < 3) {
            throw new \RuntimeException('flaking, attempt ' . self::$attempts);
        }
    }
}
