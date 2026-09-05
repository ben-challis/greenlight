<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Creates immediate and temporal expectations.
 *
 * The worker loads the configured expectation extensions before test execution.
 * Each expectation chain uses a snapshot of those extensions.
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
        return PendingEventually::create(
            \Closure::fromCallable($probe),
            ExpectationRuntime::clock(),
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
        return PendingConsistently::create(
            \Closure::fromCallable($probe),
            ExpectationRuntime::clock(),
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
     *
     * @return \Closure(): void A callback that restores the previous extension list.
     * @throws ExpectationExtensionError
     */
    public static function install(array $extensions): \Closure
    {
        $nativeMethods = \array_fill_keys(\array_map(\strtolower(...), \get_class_methods(Expectation::class)), true);

        foreach ($extensions as $extension) {
            foreach (\array_keys($extension->matchers()) as $name) {
                if (isset($nativeMethods[\strtolower($name)])) {
                    throw ExpectationExtensionError::nativeMethod($name);
                }
            }
        }

        $previous = self::$extensions;
        self::$extensions = $extensions;

        return static function () use ($previous): void {
            self::$extensions = $previous;
        };
    }
}
