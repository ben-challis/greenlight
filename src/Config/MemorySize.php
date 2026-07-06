<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Parses human-readable memory sizes into bytes. Accepted forms are a plain
 * byte count ('4096') or a positive integer with a K, M, or G suffix using
 * binary multiples ('512K', '256M', '1G'). An optional trailing 'B' is
 * tolerated ('256MB'). Anything else is rejected.
 *
 * @internal
 */
final class MemorySize
{
    private function __construct() {}

    /**
     * @return positive-int
     *
     * @throws InvalidConfiguration
     */
    public static function parseToBytes(string $value): int
    {
        $trimmed = \trim($value);

        if (\preg_match('/^(\d+)\s*([KMGkmg])?[Bb]?$/', $trimmed, $matches) !== 1) {
            throw new InvalidConfiguration(\sprintf(
                'Invalid memory size "%s". Use a positive byte count or a K, M, or G suffix, for example "256M".',
                $value,
            ));
        }

        $number = (int) $matches[1];

        if ($number < 1) {
            throw new InvalidConfiguration(\sprintf(
                'Invalid memory size "%s". The amount must be at least 1.',
                $value,
            ));
        }

        $multiplier = match (\strtoupper($matches[2] ?? '')) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };

        if ($number > \intdiv(\PHP_INT_MAX, $multiplier)) {
            throw new InvalidConfiguration(\sprintf(
                'Invalid memory size "%s". The value does not fit in an integer byte count.',
                $value,
            ));
        }

        return $number * $multiplier;
    }

    /**
     * Renders a byte count back into the shortest exact suffixed form, or a
     * plain byte count when no binary suffix divides it evenly.
     *
     * @param positive-int $bytes
     */
    public static function format(int $bytes): string
    {
        foreach (['G' => 1024 ** 3, 'M' => 1024 ** 2, 'K' => 1024] as $suffix => $multiplier) {
            if ($bytes % $multiplier === 0) {
                return \intdiv($bytes, $multiplier) . $suffix;
            }
        }

        return $bytes . 'B';
    }
}
