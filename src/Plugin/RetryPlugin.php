<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestMetadata;

/**
 * The #[Retry] attribute's policy, implemented through the same decider
 * interface user plugins get: retry up to the configured number of extra
 * attempts, only when the cause matches the declared throwable type.
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
