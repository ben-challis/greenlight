<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Test\SkipTest;

/**
 * Lets a plugin act before each test attempt in a worker.
 *
 * Greenlight runs lower priorities first. It uses registration order for equal
 * priorities. A skip or failure stops the remaining before subscribers.
 */
interface BeforeTestSubscriber extends Plugin
{
    /**
     * Greenlight calls this method after it constructs the test instance and
     * before the before hooks. `$context->skip()` or `SkipTest` reports a
     * skipped test. Other throwables cause errors that name the plugin.
     *
     * @throws SkipTest
     */
    public function beforeTest(TestContext $context): void;
}
