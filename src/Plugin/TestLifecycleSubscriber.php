<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\SkipTest;

/**
 * Lets a plugin act before and after each test attempt in a worker.
 *
 * afterTest() receives and returns the result. A plugin can return an
 * unchanged result or a replacement result. Use TestResult::withOutcome() for
 * outcome changes so that the result records their source.
 */
interface TestLifecycleSubscriber extends Plugin
{
    /**
     * Greenlight calls beforeTest() after it constructs the test instance and
     * before the before hooks. $context->skip() or SkipTest reports a skipped
     * test. Greenlight reports other throwables as errors and names this
     * plugin in each error.
     *
     * @throws SkipTest
     */
    public function beforeTest(TestContext $context): void;

    public function afterTest(TestContext $context, TestResult $result): TestResult;
}
