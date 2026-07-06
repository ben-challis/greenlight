<?php

declare(strict_types=1);

namespace Greenlight\Core\Wire;

/**
 * Typed readers for wire payloads. Every reader throws InvalidWirePayload
 * naming the offending key, so protocol errors are diagnosable from the
 * message alone. Floats tolerate integer values because JSON does not
 * preserve the distinction.
 *
 * @internal
 */
final class Wire
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function string(array $payload, string $key): string
    {
        $value = self::require($payload, $key);

        if (!\is_string($value)) {
            throw InvalidWirePayload::wrongType($key, 'a string', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return non-empty-string
     */
    public static function nonEmptyString(array $payload, string $key): string
    {
        $value = self::string($payload, $key);

        if ($value === '') {
            throw InvalidWirePayload::wrongType($key, 'a non-empty string', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function nullableString(array $payload, string $key): ?string
    {
        $value = self::require($payload, $key);

        if ($value !== null && !\is_string($value)) {
            throw InvalidWirePayload::wrongType($key, 'a string or null', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function nullableInt(array $payload, string $key): ?int
    {
        $value = self::require($payload, $key);

        if ($value !== null && !\is_int($value)) {
            throw InvalidWirePayload::wrongType($key, 'an integer or null', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function nullableFloat(array $payload, string $key): ?float
    {
        $value = self::require($payload, $key);

        if ($value === null) {
            return null;
        }

        if (\is_int($value)) {
            return (float) $value;
        }

        if (!\is_float($value)) {
            throw InvalidWirePayload::wrongType($key, 'a float or null', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function int(array $payload, string $key): int
    {
        $value = self::require($payload, $key);

        if (!\is_int($value)) {
            throw InvalidWirePayload::wrongType($key, 'an integer', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function float(array $payload, string $key): float
    {
        $value = self::require($payload, $key);

        if (\is_int($value)) {
            return (float) $value;
        }

        if (!\is_float($value)) {
            throw InvalidWirePayload::wrongType($key, 'a float', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function bool(array $payload, string $key): bool
    {
        $value = self::require($payload, $key);

        if (!\is_bool($value)) {
            throw InvalidWirePayload::wrongType($key, 'a boolean', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function map(array $payload, string $key): array
    {
        $value = self::require($payload, $key);

        if (!\is_array($value)) {
            throw InvalidWirePayload::wrongType($key, 'a map', $value);
        }

        $map = [];

        foreach ($value as $mapKey => $mapValue) {
            $map[(string) $mapKey] = $mapValue;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    public static function nullableMap(array $payload, string $key): ?array
    {
        $value = self::require($payload, $key);

        if ($value === null) {
            return null;
        }

        return self::map($payload, $key);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    public static function listOfStrings(array $payload, string $key): array
    {
        $value = self::require($payload, $key);

        if (!\is_array($value) || !\array_is_list($value)) {
            throw InvalidWirePayload::wrongType($key, 'a list of strings', $value);
        }

        $strings = [];

        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw InvalidWirePayload::wrongType($key, 'a list of strings', $item);
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    public static function listOfMaps(array $payload, string $key): array
    {
        $value = self::require($payload, $key);

        if (!\is_array($value) || !\array_is_list($value)) {
            throw InvalidWirePayload::wrongType($key, 'a list of maps', $value);
        }

        $maps = [];

        foreach ($value as $item) {
            if (!\is_array($item)) {
                throw InvalidWirePayload::wrongType($key, 'a list of maps', $item);
            }

            $map = [];

            foreach ($item as $mapKey => $mapValue) {
                $map[(string) $mapKey] = $mapValue;
            }

            $maps[] = $map;
        }

        return $maps;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function require(array $payload, string $key): mixed
    {
        if (!\array_key_exists($key, $payload)) {
            throw InvalidWirePayload::missingKey($key);
        }

        return $payload[$key];
    }
}
