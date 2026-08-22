<?php

declare(strict_types=1);

namespace Greenlight\Core\Wire;

/**
 * Reads typed values from wire payloads.
 *
 * Each reader throws InvalidWirePayload with the applicable key. Thus, its
 * message identifies the protocol error.
 *
 * A float reader accepts finite integer and float values. It accepts integers
 * because JSON does not preserve the difference between the numeric types.
 *
 * @internal
 */
final class Wire
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param array<string, mixed> $payload
     *
     * @throws WireCommunicationFailed
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
     *
     * @throws WireCommunicationFailed
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
     *
     * @throws WireCommunicationFailed
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
     *
     * @throws WireCommunicationFailed
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
     *
     * @throws WireCommunicationFailed
     */
    public static function nullableFloat(array $payload, string $key): ?float
    {
        $value = self::require($payload, $key);

        if ($value === null) {
            return null;
        }

        if (\is_int($value)) {
            $value = (float) $value;
        }

        if (!\is_float($value) || !\is_finite($value)) {
            throw InvalidWirePayload::wrongType($key, 'a finite float or null', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws WireCommunicationFailed
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
     *
     * @throws WireCommunicationFailed
     */
    public static function float(array $payload, string $key): float
    {
        $value = self::require($payload, $key);

        if (\is_int($value)) {
            $value = (float) $value;
        }

        if (!\is_float($value) || !\is_finite($value)) {
            throw InvalidWirePayload::wrongType($key, 'a finite float', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws WireCommunicationFailed
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
     * @template T of \BackedEnum
     *
     * @param array<string, mixed> $payload
     * @param class-string<T> $enum
     *
     * @return T
     *
     * @throws WireCommunicationFailed
     */
    public static function enum(array $payload, string $key, string $enum): \BackedEnum
    {
        $value = self::nonEmptyString($payload, $key);
        $case = $enum::tryFrom($value);

        if ($case === null) {
            throw InvalidWirePayload::wrongType($key, 'a ' . $enum . ' value', $value);
        }

        return $case;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws WireCommunicationFailed
     */
    public static function map(array $payload, string $key): array
    {
        $value = self::require($payload, $key);

        if (!\is_array($value) || ($value !== [] && \array_is_list($value))) {
            throw InvalidWirePayload::wrongType($key, 'a map', $value);
        }

        $map = [];

        foreach ($value as $mapKey => $mapValue) {
            if (!\is_string($mapKey)) {
                throw InvalidWirePayload::wrongType($key, 'a map with string keys', $value);
            }

            $map[$mapKey] = $mapValue;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     *
     * @throws WireCommunicationFailed
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
     *
     * @throws WireCommunicationFailed
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
     * @return list<string>|null
     *
     * @throws WireCommunicationFailed
     */
    public static function nullableListOfStrings(array $payload, string $key): ?array
    {
        $value = self::require($payload, $key);

        if ($value === null) {
            return null;
        }

        return self::listOfStrings($payload, $key);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     *
     * @throws WireCommunicationFailed
     */
    public static function listOfMaps(array $payload, string $key): array
    {
        $value = self::require($payload, $key);

        if (!\is_array($value) || !\array_is_list($value)) {
            throw InvalidWirePayload::wrongType($key, 'a list of maps', $value);
        }

        $maps = [];

        foreach ($value as $item) {
            if (!\is_array($item) || ($item !== [] && \array_is_list($item))) {
                throw InvalidWirePayload::wrongType($key, 'a list of maps', $item);
            }

            $map = [];

            foreach ($item as $mapKey => $mapValue) {
                if (!\is_string($mapKey)) {
                    throw InvalidWirePayload::wrongType($key, 'a list of maps with string keys', $item);
                }

                $map[$mapKey] = $mapValue;
            }

            $maps[] = $map;
        }

        return $maps;
    }

    /**
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
    private static function require(array $payload, string $key): mixed
    {
        if (!\array_key_exists($key, $payload)) {
            throw InvalidWirePayload::missingKey($key);
        }

        return $payload[$key];
    }
}
