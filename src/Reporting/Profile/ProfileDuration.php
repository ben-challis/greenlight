<?php

declare(strict_types=1);

namespace Greenlight\Reporting\Profile;

/**
 * Calculates finite, nonnegative profile durations.
 *
 * @internal
 */
final class ProfileDuration
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function between(float $start, float $finish): float
    {
        return self::normalize($finish - $start);
    }

    public static function add(float $left, float $right): float
    {
        $left = self::normalize($left);
        $right = self::normalize($right);

        if ($left > \PHP_FLOAT_MAX - $right) {
            return \PHP_FLOAT_MAX;
        }

        return $left + $right;
    }

    /**
     * @param list<float> $durations
     */
    public static function average(array $durations): float
    {
        $average = 0.0;

        foreach ($durations as $index => $duration) {
            $average += (self::normalize($duration) - $average) / ($index + 1);
        }

        return self::normalize($average);
    }

    private static function normalize(float $duration): float
    {
        if (\is_nan($duration) || $duration <= 0.0) {
            return 0.0;
        }

        return \is_finite($duration) ? $duration : \PHP_FLOAT_MAX;
    }
}
