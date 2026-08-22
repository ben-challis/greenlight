<?php

declare(strict_types=1);

namespace Greenlight\Internal\Wire;

/**
 * Reports a missing key or an incorrect value type in an internal wire
 * payload.
 *
 * The error always names the applicable key. Thus, its message identifies the
 * protocol error.
 *
 * @internal
 */
final class InvalidWirePayload extends WireCommunicationFailed
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

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
