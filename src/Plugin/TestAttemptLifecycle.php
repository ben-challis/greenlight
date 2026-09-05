<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Controls asynchronous work across the test body and its cleanup stages.
 * Entry uses the monotonic test deadline, before constructor injection.
 * If entry fails, the plugin releases all state that entry created.
 */
interface TestAttemptLifecycle extends Plugin
{
    public function enterTestAttempt(?float $deadline): void;

    /** Stops and joins test-body work before After hooks and deferred cleanup. */
    public function leaveTestBody(): void;

    /** Releases attempt state after deferred cleanup and test-scope disposal. */
    public function leaveTestAttempt(): void;
}
