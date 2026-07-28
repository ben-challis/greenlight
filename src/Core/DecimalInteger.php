<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * Parses nonnegative decimal text without integer overflow.
 *
 * @internal
 */
final class DecimalInteger
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @return int<0, max>|null
     */
    public static function parse(string $raw): ?int
    {
        if (\preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        $normalized = \ltrim($raw, '0');

        if ($normalized === '') {
            return 0;
        }

        $maximum = (string) \PHP_INT_MAX;

        if (\strlen($normalized) > \strlen($maximum)
            || (\strlen($normalized) === \strlen($maximum) && \strcmp($normalized, $maximum) > 0)
        ) {
            return null;
        }

        return \max(0, (int) $normalized);
    }
}
