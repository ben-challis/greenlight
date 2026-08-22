<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Result\TestResult;

/**
 * Lets a plugin act after each test attempt in a worker.
 *
 * Greenlight runs higher priorities first. It uses reverse registration order
 * for equal priorities. Plugins that implement both subscriber capabilities
 * run their after callbacks in the exact reverse order. Greenlight runs all
 * after subscribers even when a before subscriber stops the attempt.
 *
 * The method receives and returns the result. A plugin can return the same
 * result or a replacement. Use `TestResult::withOutcome()` for outcome changes
 * so that the result records their source.
 */
interface AfterTestSubscriber extends Plugin
{
    public function afterTest(TestContext $context, TestResult $result): TestResult;
}
