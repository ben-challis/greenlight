<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\RetryPolicy;

/**
 * A worker calls a retry decider after each unsuccessful attempt.
 *
 * A `true` result starts a new attempt with a new test instance and a new
 * service scope.
 *
 * `shouldRetry()` receives the retry policy, result, attempt number, and
 * optional cause. It does not receive `TestContext` because the attempt is
 * complete.
 */
interface RetryDecider extends Plugin
{
    public function shouldRetry(RetryPolicy $policy, TestResult $result, int $attempt, ?\Throwable $cause): bool;
}
