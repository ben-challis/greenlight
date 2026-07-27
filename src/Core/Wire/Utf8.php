<?php

declare(strict_types=1);

namespace Greenlight\Core\Wire;

/**
 * Converts strings to valid UTF-8 for a wire payload.
 *
 * JSON wire payloads require valid UTF-8. Strings from user code can contain
 * invalid bytes. For example, exception messages and rendered values can
 * contain these bytes. scrub() replaces each invalid sequence with U+FFFD.
 *
 * @internal
 */
final class Utf8
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function scrub(string $value): string
    {
        if (\preg_match('//u', $value) === 1) {
            return $value;
        }

        $encoded = \json_encode($value, \JSON_INVALID_UTF8_SUBSTITUTE);

        if (\is_string($encoded)) {
            $decoded = \json_decode($encoded);

            if (\is_string($decoded)) {
                return $decoded;
            }
        }

        return '(unrepresentable binary string)';
    }
}
