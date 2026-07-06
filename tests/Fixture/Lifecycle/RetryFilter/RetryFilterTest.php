<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\RetryFilter;

use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Test;

final class RetryFilterTest
{
    public static int $attempts = 0;

    #[Test]
    #[Retry(times: 2, onlyOn: \DomainException::class)]
    public function failsWithTheWrongException(): never
    {
        ++self::$attempts;

        throw new \LogicException('not retryable');
    }
}
