<?php

declare(strict_types=1);

namespace Greenlight\Core\Wire;

/**
 * Scrubs strings to valid UTF-8 so they can enter a wire payload.
 *
 * Wire payloads are JSON, and JSON requires valid UTF-8. Strings that
 * originate in user code (exception messages, rendered values) can contain
 * arbitrary bytes, so they are scrubbed at capture: scrub() replaces invalid
 * sequences with U+FFFD.
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
