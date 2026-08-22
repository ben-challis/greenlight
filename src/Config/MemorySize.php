<?php

declare(strict_types=1);

namespace Greenlight\Config;

use Greenlight\Internal\Text\DecimalInteger;

/**
 * Converts memory-size text to bytes.
 *
 * parseToBytes() accepts a byte count ('4096') or a positive integer with a K,
 * M, or G suffix ('512K', '256M', '1G'). The suffixes specify binary
 * multiples. An optional final 'B' is valid ('256MB'). The method rejects all
 * other forms.
 *
 * @internal
 */
final class MemorySize
{
    /** @codeCoverageIgnore */
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

        $number = DecimalInteger::parse($matches[1]);

        if ($number === null) {
            throw new InvalidConfiguration(\sprintf(
                'Invalid memory size "%s". The value does not fit in an integer byte count.',
                $value,
            ));
        }

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
     * Converts a byte count to the shortest exact form with a suffix. If no
     * binary suffix divides the value evenly, the method returns a byte count
     * without a suffix.
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
