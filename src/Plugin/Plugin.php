<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Identifies an object as a Greenlight plugin.
 *
 * Plugins implement one or more capability interfaces such as
 * `WorkerRuntimeRunner`, `TestAttemptRunner`, `BeforeTestSubscriber`,
 * `AfterTestSubscriber`, `RunLifecycleSubscriber`, `RetryDecider`,
 * `CommandProvider`, `CoverageMapTransformer`, `HarnessProvider`, `ReporterProvider`,
 * `TerminalResultTransformer`, `TestPlanTransformer`, `WatchSource`, or
 * `ExpectationExtension`.
 */
interface Plugin {}
