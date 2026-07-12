<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestMetadata;

/**
 * The RetryDecider for the #[Retry] attribute.
 *
 * shouldRetry() answers yes until the attribute's extra attempts are used
 * up. When the attribute names a throwable type, the cause must be an
 * instance of it; tests without the attribute are never retried.
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
