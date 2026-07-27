<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestMetadata;

/**
 * Implements RetryDecider for the #[Retry] attribute.
 *
 * shouldRetry() returns true while additional attempts remain unused. If the
 * attribute specifies a throwable type, the cause must have that type. The
 * method returns false if the test does not have the attribute.
 *
 * @internal
 */
final readonly class RetryPlugin implements RetryDecider
{
    #[\Override]
    public function shouldRetry(TestMetadata $metadata, TestResult $result, int $attempt, ?\Throwable $cause): bool
    {
        $times = $metadata->retryTimes;

        if ($times === null || $attempt > $times) {
            return false;
        }

        $onlyOn = $metadata->retryOnlyOn;

        if ($onlyOn !== null && !($cause instanceof $onlyOn)) {
            return false;
        }

        return true;
    }
}
