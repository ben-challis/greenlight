<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Deep equality used by Expectation::toEqual().
 *
 * The full semantics are documented on the Expectation class docblock; this
 * class only implements them.
 *
 * @internal
 */
final class Equality
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function equals(mixed $a, mixed $b): bool
    {
        return self::compare($a, $b, []);
    }

    /**
     * Like equals(), but list order is irrelevant: lists are sorted by a
     * stable serialization of their canonicalized elements before comparing,
     * recursively. Associative arrays keep their keys and everything else
     * follows the equals() semantics.
     */
    public static function equalsCanonicalizing(mixed $a, mixed $b): bool
    {
        return self::compare(self::canonicalize($a), self::canonicalize($b), []);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $canonical = \array_map(self::canonicalize(...), $value);

        if (\array_is_list($canonical)) {
            // Keys are precomputed once per element; sorting with a comparator
            // would re-serialize both operands on every comparison.
            $keys = \array_map(static fn(mixed $item): string => self::sortKey($item, []), $canonical);
            \array_multisort($keys, \SORT_ASC, \SORT_STRING, $canonical);
        }

        return $canonical;
    }

    /**
     * Serializes a canonicalized value into a stable ordering key. Numbers
     * share one representation so 1 and 1.0 sort together; objects without
     * comparable state (closures, resources) fall back to identity.
     *
     * @param list<int> $seen object ids already on the serialization stack,
     *   so cyclic structures terminate
     */
    private static function sortKey(mixed $value, array $seen): string
    {
        if (\is_array($value)) {
            $parts = [];

            foreach ($value as $key => $item) {
                $parts[] = \var_export($key, true) . '=>' . self::sortKey($item, $seen);
            }

            return '[' . \implode(',', $parts) . ']';
        }

        if (\is_int($value)) {
            // Beyond 2**53 a float cannot hold the int exactly; keeping the
            // exact digits stops distinct large ints from colliding on one
            // key, which would freeze them in their original list positions.
            return \abs($value) <= 2 ** 53
                ? 'number:' . (float) $value
                : 'number:' . $value;
        }

        if (\is_float($value)) {
            return 'number:' . $value;
        }

        if ($value instanceof \Closure) {
            return 'Closure#' . \spl_object_id($value);
        }

        if (\is_object($value)) {
            $id = \spl_object_id($value);

            if (\in_array($id, $seen, true)) {
                return $value::class . '{...}';
            }

            $seen[] = $id;
            $parts = [];

            foreach (\get_mangled_object_vars($value) as $name => $item) {
                $parts[] = $name . '=>' . self::sortKey($item, $seen);
            }

            return $value::class . '{' . \implode(',', $parts) . '}';
        }

        if (\is_resource($value)) {
            return 'resource#' . \get_resource_id($value);
        }

        return \get_debug_type($value) . ':' . \var_export($value, true);
    }

    /**
     * @param list<non-empty-string> $comparing object pairs already on the
     *   comparison stack, so cyclic structures terminate
     */
    private static function compare(mixed $a, mixed $b, array $comparing): bool
    {
        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            if (\is_int($a) && \is_int($b)) {
                return $a === $b;
            }

            return (float) $a === (float) $b;
        }

        if (\is_array($a) && \is_array($b)) {
            if (\count($a) !== \count($b)) {
                return false;
            }
            return \array_all($a, fn($value, $key) => \array_key_exists($key, $b) && self::compare($value, $b[$key], $comparing));
        }

        if ($a instanceof \UnitEnum || $b instanceof \UnitEnum) {
            return $a === $b;
        }

        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return $a->format('U.u') === $b->format('U.u');
        }

        if ($a instanceof \Closure || $b instanceof \Closure) {
            return $a === $b;
        }

        if (\is_object($a) && \is_object($b)) {
            if ($a::class !== $b::class) {
                return false;
            }

            if ($a === $b) {
                return true;
            }

            $pair = \spl_object_id($a) . ':' . \spl_object_id($b);

            if (\in_array($pair, $comparing, true)) {
                return true;
            }

            $comparing[] = $pair;

            $aProperties = \get_mangled_object_vars($a);
            $bProperties = \get_mangled_object_vars($b);

            if (\count($aProperties) !== \count($bProperties)) {
                return false;
            }
            return \array_all($aProperties, fn($value, $name) => \array_key_exists($name, $bProperties) && self::compare($value, $bProperties[$name], $comparing));
        }

        return $a === $b;
    }
}
