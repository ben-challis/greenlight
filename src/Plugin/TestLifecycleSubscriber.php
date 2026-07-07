<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Result\TestResult;

/**
 * Worker-side interception around each test attempt.
 *
 * afterTest() receives the result and returns it, replaced or untouched;
 * outcome changes are only legal through TestResult::withOutcome() so every
 * change carries provenance.
 */
interface TestLifecycleSubscriber
{
    /**
     * Runs after the test instance is constructed and before the before-hooks.
     * Calling $context->skip() (or throwing SkipTest directly) reports the
     * test as skipped; any other throwable errors it with this plugin named.
     *
     * @throws SkipTest
     */
    public function beforeTest(TestContext $context): void;

    public function afterTest(TestContext $context, TestResult $result): TestResult;
}
