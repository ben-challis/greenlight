<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Test\TestId;

/** Detects test instances that remain reachable after their test. */
interface TestInstanceLeakDetector extends Plugin
{
    /** Records one test instance before Greenlight releases its references. */
    public function watch(TestId $id, object $instance): void;

    /**
     * Reports leaks after Greenlight releases its references to the test.
     *
     * A detector MUST report each leak one time.
     *
     * @return list<TestId>
     */
    public function sweep(): array;
}
