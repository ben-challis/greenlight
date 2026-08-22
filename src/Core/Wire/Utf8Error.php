<?php

declare(strict_types=1);

namespace Greenlight\Core\Wire;

/**
 * Greenlight raises this error when it cannot convert a string to valid UTF-8.
 *
 * @internal
 */
final class Utf8Error extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function jsonConversionFailed(\JsonException $previous): self
    {
        return new self(
            'Cannot convert the string to valid UTF-8: ' . $previous->getMessage() . '.',
            $previous,
        );
    }

    public static function unexpectedDecodedType(mixed $value): self
    {
        return new self(\sprintf(
            'Cannot convert the string to valid UTF-8: JSON returned %s instead of a string.',
            \get_debug_type($value),
        ));
    }
}
