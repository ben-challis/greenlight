<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Extension matchers are worker-local state. `install()` stores the configured
 * `ExpectationExtension` list when the worker starts. Each chain from `that()`
 * uses a snapshot of this list. The runner changes the list before test
 * execution starts. Before `install()` runs, `that()` uses no extensions.
 */
final class Expect
{
    /**
     * @var list<ExpectationExtension>
     */
    private static array $extensions = [];

    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @template T
     *
     * @param T $value
     *
     * @return Expectation<T>
     */
    public static function that(mixed $value): Expectation
    {
        return new Expectation($value, new ValueRenderer(), self::$extensions);
    }

    /**
     * Polls the probe until its matcher passes or the deadline expires.
     *
     * @template T
     *
     * @param callable(): T $probe
     *
     * @return PendingEventually<T>
     */
    public static function eventually(callable $probe): PendingEventually
    {
        return new PendingEventually(
            \Closure::fromCallable($probe),
            ExpectationRuntime::clock(),
            ExpectationRuntime::deadline(),
            new ValueRenderer(),
            self::$extensions,
        );
    }

    /**
     * Polls the probe for a fixed period and fails on the first mismatch.
     *
     * @template T
     *
     * @param callable(): T $probe
     *
     * @return PendingConsistently<T>
     */
    public static function consistently(callable $probe): PendingConsistently
    {
        return new PendingConsistently(
            \Closure::fromCallable($probe),
            ExpectationRuntime::clock(),
            ExpectationRuntime::deadline(),
            new ValueRenderer(),
            self::$extensions,
        );
    }

    /**
     * Replaces the worker-local extension list for subsequent `that()` chains.
     * The runner calls this method when the worker starts. A test that installs
     * extensions must restore the previous list.
     *
     * @internal
     *
     * @param list<ExpectationExtension> $extensions
     */
    public static function install(array $extensions): void
    {
        self::$extensions = $extensions;
    }
}
