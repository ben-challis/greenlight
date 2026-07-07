<?php

declare(strict_types=1);

namespace Greenlight\Core\Wire;

/**
 * Raised when a decoded wire payload is missing a required key or carries the
 * wrong type for one. Always names the offending key so protocol errors are
 * diagnosable from the message alone.
 */
final class InvalidWirePayload extends \RuntimeException
{
    public static function missingKey(string $key): self
    {
        return new self(\sprintf('Wire payload is missing the "%s" key.', $key));
    }

    public static function wrongType(string $key, string $expected, mixed $actual): self
    {
        return new self(\sprintf(
            'Wire payload key "%s" must be %s, got %s.',
            $key,
            $expected,
            \get_debug_type($actual),
        ));
    }
}
