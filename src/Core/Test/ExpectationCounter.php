<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

/**
 * Process-local count of verified expectations, used to spot tests that
 * assert nothing.
 *
 * The executor calls reset() before each attempt and count() after per-test
 * teardown, so double verification at scope close is included. Expect and
 * Doubles call increment() on every verification, pass or fail.
 *
 * A static counter is deliberate: harness service factories receive no
 * resolver, workers are single-threaded, and the executor owns the
 * reset/read lifecycle.
 *
 * @internal
 */
final class ExpectationCounter
{
    private static int $count = 0;

    private function __construct() {}

    public static function reset(): void
    {
        self::$count = 0;
    }

    public static function increment(): void
    {
        ++self::$count;
    }

    /**
     * @return non-negative-int
     */
    public static function count(): int
    {
        return \max(0, self::$count);
    }
}
