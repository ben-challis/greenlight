<?php

declare(strict_types=1);

namespace Greenlight\Test;

/**
 * Counts verified expectations in one process. This count identifies tests
 * that verify no expectations.
 *
 * The executor calls reset() before each attempt. It calls count() after test
 * teardown. Thus, the count includes double verification at scope close.
 * Expect and Doubles call increment() for each successful or failed
 * verification.
 *
 * The static counter supports the design. Harness service factories do not
 * receive a resolver. Each worker runs one test attempt at a time. The executor
 * controls the reset and read operations.
 * Suppression applies only to the current Fiber or the main context.
 *
 * @internal
 */
final class ExpectationCounter
{
    private static int $count = 0;

    private static int $mainSuppressionDepth = 0;

    /** @var \WeakMap<object, int>|null */
    private static ?\WeakMap $fiberSuppressionDepths = null;

    private static int $generation = 0;

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function reset(): void
    {
        self::$count = 0;
        self::$mainSuppressionDepth = 0;
        self::$fiberSuppressionDepths = null;
        ++self::$generation;
    }

    public static function increment(): void
    {
        if (self::suppressionDepth() === 0) {
            ++self::$count;
        }
    }

    /**
     * Excludes the current context's expectations from the count during an operation.
     *
     * @internal
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public static function withoutCounting(\Closure $operation): mixed
    {
        $fiber = \Fiber::getCurrent();
        $previous = self::suppressionDepth();
        $generation = self::$generation;
        $fiberSuppressionDepths = self::$fiberSuppressionDepths ??= new \WeakMap();

        if (!$fiber instanceof \Fiber) {
            self::$mainSuppressionDepth = $previous + 1;
        } else {
            $fiberSuppressionDepths[$fiber] = $previous + 1;
        }

        try {
            return $operation();
        } finally {
            if (self::$generation === $generation) {
                if (!$fiber instanceof \Fiber) {
                    self::$mainSuppressionDepth = $previous;
                } elseif ($previous === 0) {
                    unset($fiberSuppressionDepths[$fiber]);
                } else {
                    $fiberSuppressionDepths[$fiber] = $previous;
                }
            }
        }
    }

    /**
     * @return non-negative-int
     */
    public static function count(): int
    {
        return \max(0, self::$count);
    }

    private static function suppressionDepth(): int
    {
        $fiber = \Fiber::getCurrent();

        return $fiber instanceof \Fiber
            ? (self::$fiberSuppressionDepths[$fiber] ?? 0)
            : self::$mainSuppressionDepth;
    }
}
