<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Internal\Text\Utf8;

/**
 * Bounds result text from user code to a size limit.
 *
 * The worker sends each result in one JSON protocol frame. The frame has a
 * size limit, so each text value in a result must have a size limit too.
 * Each limit applies to the JSON-encoded size of the text. JSON escapes
 * control bytes and non-ASCII characters, so one source byte can need up to
 * six encoded bytes.
 *
 * A bounded value is valid UTF-8. It keeps the first part of the text and ends
 * with a truncation marker.
 *
 * @internal
 */
final class BoundedText
{
    /** Contains the encoded size limit for each string in a result wire payload. */
    public const int MAX_WIRE_STRING_BYTES = 1_048_576;

    private const string MARKER = '... (truncated)';
    private const int MAX_ESCAPE_FACTOR = 6;
    private const int JSON_FLAGS = \JSON_INVALID_UTF8_SUBSTITUTE;

    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param int<16, max> $maxEncodedBytes
     *
     * @return ($value is non-empty-string ? non-empty-string : string)
     */
    public static function head(string $value, int $maxEncodedBytes): string
    {
        $value = Utf8::scrub($value);

        if (\strlen($value) * self::MAX_ESCAPE_FACTOR <= $maxEncodedBytes
            || self::encodedBytes($value) <= $maxEncodedBytes
        ) {
            return $value;
        }

        // The encoded size grows with the prefix length, and it is never
        // smaller than the raw size.
        $budget = $maxEncodedBytes - \strlen(self::MARKER);
        $low = 0;
        $high = \min(\strlen($value), $budget);

        while ($low < $high) {
            $middle = \intdiv($low + $high + 1, 2);

            if (self::encodedBytes(self::prefix($value, $middle)) <= $budget) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return self::prefix($value, $low) . self::MARKER;
    }

    /**
     * Applies the wire string limit to each string in a payload.
     *
     * @template K of array-key
     *
     * @param array<K, mixed> $payload
     *
     * @return array<K, mixed>
     */
    public static function wire(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (\is_string($value)) {
                $payload[$key] = self::head($value, self::MAX_WIRE_STRING_BYTES);
            } elseif (\is_array($value)) {
                $payload[$key] = self::wire($value);
            }
        }

        return $payload;
    }

    /** Returns the size of the JSON string body without its quotes. */
    private static function encodedBytes(string $value): int
    {
        $encoded = \json_encode($value, self::JSON_FLAGS);

        return $encoded === false ? \PHP_INT_MAX : \strlen($encoded) - 2;
    }

    /** Returns the longest valid UTF-8 prefix of at most the given byte count. */
    private static function prefix(string $value, int $bytes): string
    {
        $prefix = \substr($value, 0, $bytes);

        while ($prefix !== '' && \preg_match('//u', $prefix) !== 1) {
            $prefix = \substr($prefix, 0, -1);
        }

        return $prefix;
    }
}
