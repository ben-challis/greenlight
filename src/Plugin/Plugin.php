<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Identifies an object as a Greenlight plugin.
 *
 * Plugins implement one or more capability interfaces such as
 * `WorkerRuntimeRunner`, `TestAttemptRunner`, `TestLifecycleSubscriber`,
 * `RunLifecycleSubscriber`, `RetryDecider`, `HarnessProvider`, or
 * `ExpectationExtension`.
 */
interface Plugin {}
