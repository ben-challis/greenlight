<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

/**
 * Simulates the wire in unit tests: encodes a payload to JSON and decodes it
 * back, so wire round-trip assertions exercise real JSON semantics.
 */
final class JsonWire
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function roundTrip(array $payload): array
    {
        $decoded = \json_decode(\json_encode($payload, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \RuntimeException('Wire payload did not decode to an array.');
        }

        $map = [];

        foreach ($decoded as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }
}
