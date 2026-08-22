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
 *
 * @internal
 */
final class ExpectationCounter
{
    private static int $count = 0;

    private static int $suppressionDepth = 0;

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function reset(): void
    {
        self::$count = 0;
        self::$suppressionDepth = 0;
    }

    public static function increment(): void
    {
        if (self::$suppressionDepth === 0) {
            ++self::$count;
        }
    }

    /**
     * Runs an operation without a change to the expectation count.
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
        ++self::$suppressionDepth;

        try {
            return $operation();
        } finally {
            --self::$suppressionDepth;
        }
    }

    /**
     * @return non-negative-int
     */
    public static function count(): int
    {
        return \max(0, self::$count);
    }
}
