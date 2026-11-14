<?php

declare(strict_types=1);

namespace Greenlight\Internal\Php;

/**
 * Provides array_any(), array_all(), and array_find() for PHP 8.3.
 *
 * Each method has the same behavior as the PHP 8.4 function of the same
 * name. Greenlight does not define global functions, because a global
 * definition can hide a missing function in the project under test.
 *
 * @internal
 */
final class Arrays
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array
     * @param callable(TValue, TKey): bool $callback
     */
    public static function any(array $array, callable $callback): bool
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array
     * @param callable(TValue, TKey): bool $callback
     */
    public static function all(array $array, callable $callback): bool
    {
        foreach ($array as $key => $value) {
            if (!$callback($value, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array
     * @param callable(TValue, TKey): bool $callback
     *
     * @return TValue|null
     */
    public static function find(array $array, callable $callback): mixed
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return null;
    }
}
