<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Entry point of the expectation API.
 *
 * Anchor a matcher chain on a subject with the static that(). A failed
 * matcher throws ExpectationFailed immediately.
 *
 * Extension matchers are worker-local state: install() stores the configured
 * ExpectationExtension list once at worker boot, and every chain created by
 * that() dispatches through it. Workers are single-threaded and the runner
 * owns the install point, so the static registry is never observed
 * mid-mutation. Before install() runs, that() works with no extensions.
 */
final class Expect
{
    /**
     * @var list<ExpectationExtension>
     */
    private static array $extensions = [];

    /** @codeCoverageIgnore */
    private function __construct() {}

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
     * Replaces the worker-local extension list consulted by every subsequent
     * that() chain. Called once per worker at boot; tests that install their
     * own extensions must restore the previous list themselves.
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
