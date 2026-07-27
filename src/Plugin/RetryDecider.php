<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestMetadata;

/**
 * A retry decider that a worker calls after each unsuccessful attempt.
 *
 * A yes result starts a new attempt with a new test instance in a new service
 * scope.
 *
 * `shouldRetry()` receives the metadata, result, and optional cause. It does not
 * receive a context because the test instance no longer exists.
 */
interface RetryDecider extends Plugin
{
    public function shouldRetry(TestMetadata $metadata, TestResult $result, int $attempt, ?\Throwable $cause): bool;
}
