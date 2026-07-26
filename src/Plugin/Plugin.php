<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Marks an object as a Greenlight plugin.
 *
 * Plugins implement one or more capability interfaces such as
 * TestLifecycleSubscriber, RunLifecycleSubscriber, RetryDecider,
 * HarnessProvider, or ExpectationExtension.
 */
interface Plugin {}
