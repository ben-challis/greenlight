<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/** @internal */
final class Equality
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function equals(mixed $a, mixed $b): bool
    {
        $leftObjects = [];
        $rightObjects = [];

        return self::compare($a, $b, $leftObjects, $rightObjects);
    }

    /**
     * Compares values with the equals() rules, but ignores list order. The
     * method recursively converts each list element to a canonical form. It
     * then sorts lists by a stable representation. Associative arrays keep
     * their keys.
     */
    public static function equalsCanonicalizing(mixed $a, mixed $b): bool
    {
        $leftObjects = [];
        $rightObjects = [];

        return self::compare(self::canonicalize($a), self::canonicalize($b), $leftObjects, $rightObjects);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $canonical = \array_map(self::canonicalize(...), $value);

        if (\array_is_list($canonical)) {
            // Compute each key one time for each element. A comparator
            // serializes both operands again for each comparison.
            $keys = \array_map(static fn(mixed $item): string => self::sortKey($item, []), $canonical);
            \array_multisort($keys, \SORT_ASC, \SORT_STRING, $canonical);
        }

        return $canonical;
    }

    /**
     * Converts a canonical value to a stable sort key. Numbers use one
     * representation, so 1 and 1.0 have the same sort position. Closures and
     * resources use their identity because they have no comparable state.
     *
     * @param list<int> $seen Object IDs already in the conversion stack. This
     *   list stops cycles.
     */
    private static function sortKey(mixed $value, array $seen): string
    {
        if (\is_array($value)) {
            $parts = [];
            \ksort($value, \SORT_STRING);

            foreach ($value as $key => $item) {
                $parts[] = \var_export($key, true) . '=>' . self::sortKey($item, $seen);
            }

            return '[' . \implode(',', $parts) . ']';
        }

        if (\is_int($value)) {
            // A float cannot hold an integer above 2**53 exactly. Keep the
            // exact digits to give different large integers different keys.
            // Otherwise, the integers can remain in their initial positions.
            return \abs($value) <= 2 ** 53
                ? 'number:' . (float) $value
                : 'number:' . $value;
        }

        if (\is_float($value)) {
            return 'number:' . $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return 'DateTime:' . $value->format('U.u');
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
            $properties = \get_mangled_object_vars($value);
            \ksort($properties, \SORT_STRING);

            foreach ($properties as $name => $item) {
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
     * @param array<int, int> $leftObjects Object mappings from the left value
     *   to the right value
     * @param array<int, int> $rightObjects Object mappings from the right value
     *   to the left value
     */
    private static function compare(
        mixed $a,
        mixed $b,
        array &$leftObjects,
        array &$rightObjects,
    ): bool {
        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            if (\is_int($a) && \is_int($b)) {
                return $a === $b;
            }

            if (\is_float($a) && \is_float($b)) {
                return $a === $b;
            }

            $integer = \is_int($a) ? $a : $b;
            $float = \is_float($a) ? $a : $b;

            return (float) $integer === $float && (int) $float === $integer;
        }

        if (\is_array($a) && \is_array($b)) {
            if (\count($a) !== \count($b)) {
                return false;
            }
            return \array_all(
                $a,
                static function ($value, $key) use ($b, &$leftObjects, &$rightObjects): bool {
                    return \array_key_exists($key, $b)
                        && self::compare($value, $b[$key], $leftObjects, $rightObjects);
                },
            );
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

            $leftId = \spl_object_id($a);
            $rightId = \spl_object_id($b);

            if (isset($leftObjects[$leftId]) || isset($rightObjects[$rightId])) {
                return ($leftObjects[$leftId] ?? null) === $rightId
                    && ($rightObjects[$rightId] ?? null) === $leftId;
            }

            $leftObjects[$leftId] = $rightId;
            $rightObjects[$rightId] = $leftId;

            if ($a === $b) {
                return true;
            }

            $aProperties = \get_mangled_object_vars($a);
            $bProperties = \get_mangled_object_vars($b);

            if (\count($aProperties) !== \count($bProperties)) {
                return false;
            }
            return \array_all(
                $aProperties,
                static function ($value, $name) use ($bProperties, &$leftObjects, &$rightObjects): bool {
                    return \array_key_exists($name, $bProperties)
                        && self::compare($value, $bProperties[$name], $leftObjects, $rightObjects);
                },
            );
        }

        return $a === $b;
    }
}
