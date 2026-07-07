<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestMetadata;

/**
 * Worker-side retry policy, consulted after each unsuccessful attempt.
 *
 * Any decider answering yes triggers a fresh attempt with a fresh instance
 * and scope.
 *
 * shouldRetry() receives metadata and the causing throwable rather than a
 * context: the failed attempt's instance is already gone when this runs.
 */
interface RetryDecider
{
    public function shouldRetry(TestMetadata $metadata, TestResult $result, int $attempt, ?\Throwable $cause): bool;
}
