<?php

declare(strict_types=1);

namespace Greenlight\Internal\Text;

/**
 * Scrubs and bounds UTF-8 text for internal diagnostics and formats.
 *
 * Strings from user code can contain invalid bytes. For example, exception
 * messages and rendered values can contain these bytes. scrub() replaces each
 * invalid sequence with U+FFFD.
 *
 * @internal
 */
final class Utf8
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function headBytes(string $value, int $maxBytes): string
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException(\sprintf('Byte bound must be zero or greater, got %d.', $maxBytes));
        }

        if ($maxBytes === 0) {
            return '';
        }

        $value = self::scrub($value);

        if (\strlen($value) <= $maxBytes) {
            return $value;
        }

        $bounded = \substr($value, 0, $maxBytes);

        while (\preg_match('//u', $bounded) !== 1) {
            $bounded = \substr($bounded, 0, -1);
        }

        return $bounded;
    }

    public static function tailBytes(string $value, int $maxBytes): string
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException(\sprintf('Byte bound must be zero or greater, got %d.', $maxBytes));
        }

        if ($maxBytes === 0) {
            return '';
        }

        $value = self::scrub($value);

        if (\strlen($value) <= $maxBytes) {
            return $value;
        }

        $bounded = \substr($value, -$maxBytes);

        while (\preg_match('//u', $bounded) !== 1) {
            $bounded = \substr($bounded, 1);
        }

        return $bounded;
    }

    public static function scrub(string $value): string
    {
        if (\preg_match('//u', $value) === 1) {
            return $value;
        }

        $encoded = \json_encode(
            $value,
            \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
        );
        $decoded = \json_decode($encoded, flags: \JSON_THROW_ON_ERROR);

        return \is_string($decoded) ? $decoded : '(unrepresentable binary string)';
    }
}
