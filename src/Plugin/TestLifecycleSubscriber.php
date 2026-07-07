<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Result\TestResult;

/**
 * Worker-side interception around each test attempt. beforeTest runs after
 * construction and before the before-hooks; throwing SkipTest skips the test,
 * any other throwable errors it with this plugin named. afterTest receives
 * the result and returns it, replaced or untouched; outcome changes are only
 * legal through TestResult::withOutcome() so every change carries provenance.
 */
interface TestLifecycleSubscriber
{
    public function beforeTest(TestContext $context): void;

    public function afterTest(TestContext $context, TestResult $result): TestResult;
}
