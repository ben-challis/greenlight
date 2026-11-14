<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;

/**
 * Lets a plugin release per-attempt state after each test attempt in a worker.
 *
 * Greenlight calls `testAttemptEnded()` one time for each attempt that starts.
 * It also calls the method when the test constructor, constructor injection,
 * or output capture fails. In these cases Greenlight has no test instance and
 * does not call `afterTest()`.
 *
 * The method runs after all `afterTest()` subscribers and before the retry
 * decision. It runs in the `TestAttemptRunner` boundary.
 *
 * Greenlight runs higher priorities first. It uses reverse registration order
 * for equal priorities. A throwable does not stop the remaining subscribers.
 * Greenlight adds the failure to the test result and names the plugin.
 */
interface TestAttemptEndSubscriber extends Plugin
{
    public function testAttemptEnded(TestId $id, TestDefinition $definition): void;
}
