<?php

declare(strict_types=1);

namespace Greenlight\Execution\Plugin;

use Greenlight\Plugin\RetryDecider;
use Greenlight\Result\TestResult;
use Greenlight\Test\RetryPolicy;

/**
 * Implements retry decisions for the Retry attribute.
 *
 * @internal
 */
final readonly class AttributeRetryDecider implements RetryDecider
{
    #[\Override]
    public function shouldRetry(RetryPolicy $policy, TestResult $result, int $attempt, ?\Throwable $cause): bool
    {
        $times = $policy->times;

        if ($times === null || $attempt > $times) {
            return false;
        }

        $onlyOn = $policy->onlyOn;

        if ($onlyOn !== null && !($cause instanceof $onlyOn)) {
            return false;
        }

        return true;
    }
}
