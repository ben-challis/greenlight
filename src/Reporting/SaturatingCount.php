<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * Adds nonnegative counts without integer overflow.
 *
 * @internal
 */
final class SaturatingCount
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param non-negative-int $total
     * @param non-negative-int $increment
     *
     * @return non-negative-int
     */
    public static function add(int $total, int $increment): int
    {
        return $increment > \PHP_INT_MAX - $total
            ? \PHP_INT_MAX
            : $total + $increment;
    }
}
