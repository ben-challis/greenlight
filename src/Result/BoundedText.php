<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Internal\Text\Utf8;

/**
 * Bounds result text from user code to a byte limit.
 *
 * The worker sends each result in one protocol frame. The frame has a size
 * limit, so each text value in a result must have a size limit too. A bounded
 * value is valid UTF-8. It keeps the first part of the text and ends with a
 * truncation marker.
 *
 * @internal
 */
final class BoundedText
{
    /** Contains the byte limit for each string in a result wire payload. */
    public const int MAX_WIRE_STRING_BYTES = 1_048_576;

    private const string MARKER = '... (truncated)';

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function head(string $value, int $maxBytes): string
    {
        $value = Utf8::scrub($value);

        if (\strlen($value) <= $maxBytes) {
            return $value;
        }

        if ($maxBytes <= \strlen(self::MARKER)) {
            return Utf8::headBytes($value, $maxBytes);
        }

        return Utf8::headBytes($value, $maxBytes - \strlen(self::MARKER)) . self::MARKER;
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
}
