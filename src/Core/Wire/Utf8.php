<?php

declare(strict_types=1);

namespace Greenlight\Core\Wire;

/**
 * Wire payloads are JSON, and JSON requires valid UTF-8. Strings that originate
 * in user code (exception messages, rendered values) can contain arbitrary
 * bytes, so they are scrubbed at capture: invalid sequences become U+FFFD.
 *
 * @internal
 */
final class Utf8
{
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
