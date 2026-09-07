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
        $arrayPairs = [];

        return self::compare($a, $b, $leftObjects, $rightObjects, $arrayPairs);
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
        $arrayPairs = [];
        $leftArrays = [];
        $rightArrays = [];

        return self::compare(
            self::canonicalize($a, $leftArrays),
            self::canonicalize($b, $rightArrays),
            $leftObjects,
            $rightObjects,
            $arrayPairs,
        );
    }

    /** @param array<string, array<mixed>> $arrays Canonical array reference targets */
    private static function canonicalize(mixed $value, array &$arrays): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        return self::canonicalizeArray($value, $arrays);
    }

    /**
     * @param array<mixed> $value
     * @param array<string, array<mixed>> $arrays
     *
     * @return array<mixed>
     */
    private static function canonicalizeArray(array $value, array &$arrays): array
    {
        $canonical = [];

        foreach ($value as $key => $item) {
            $reference = \is_array($item) ? \ReflectionReference::fromArrayElement($value, $key) : null;

            if (\is_array($item) && $reference !== null) {
                $id = $reference->getId();

                if (!\array_key_exists($id, $arrays)) {
                    // Register the target before following a reference back to it.
                    $arrays[$id] = [];
                    $arrays[$id] = self::canonicalizeArray($item, $arrays);
                }

                $canonical[$key] = &$arrays[$id];
            } else {
                $canonical[$key] = self::canonicalize($item, $arrays);
            }
        }

        if (\array_is_list($canonical)) {
            // Compute each key one time for each element. A comparator
            // serializes both operands again for each comparison.
            $keys = \array_map(static fn(mixed $item): string => self::sortKey($item, [], []), $canonical);
            \uksort($canonical, static fn(int $left, int $right): int => \strcmp($keys[$left], $keys[$right]));
            $canonical = \array_values($canonical);
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
     * @param list<string> $seenArrays Array reference IDs in the conversion stack
     */
    private static function sortKey(mixed $value, array $seen, array $seenArrays): string
    {
        if (\is_array($value)) {
            $parts = [];
            \ksort($value, \SORT_STRING);

            foreach ($value as $key => $item) {
                $arrayId = \is_array($item) ? \ReflectionReference::fromArrayElement($value, $key)?->getId() : null;
                $keyPrefix = \var_export($key, true) . '=>';

                if ($arrayId !== null && \in_array($arrayId, $seenArrays, true)) {
                    $parts[] = $keyPrefix . '[...]';

                    continue;
                }

                $parts[] = $keyPrefix . self::sortKey(
                    $item,
                    $seen,
                    $arrayId === null ? $seenArrays : [...$seenArrays, $arrayId],
                );
            }

            return '[' . \implode(',', $parts) . ']';
        }

        if (\is_int($value) || \is_float($value)) {
            if (\is_int($value) && (int) (float) $value !== $value) {
                return 'integer:' . $value;
            }

            $number = (float) $value;

            // Both signs of zero compare equal and need the same key.
            if ($number === 0.0) {
                return 'number:zero';
            }

            // Keep every float bit without depending on display precision.
            return 'number:' . \bin2hex(\pack('E', $number));
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
                $parts[] = \var_export($name, true) . '=>' . self::sortKey($item, $seen, $seenArrays);
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
     * @param array<string, true> $arrayPairs Array reference pairs already compared
     */
    private static function compare(
        mixed $a,
        mixed $b,
        array &$leftObjects,
        array &$rightObjects,
        array &$arrayPairs,
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

            foreach ($a as $key => $value) {
                if (!\array_key_exists($key, $b)) {
                    return false;
                }

                if (\is_array($value) && \is_array($b[$key])) {
                    $leftReference = \ReflectionReference::fromArrayElement($a, $key);
                    $rightReference = \ReflectionReference::fromArrayElement($b, $key);

                    if ($leftReference !== null && $rightReference !== null) {
                        $pair = $leftReference->getId() . $rightReference->getId();

                        if (isset($arrayPairs[$pair])) {
                            continue;
                        }

                        $arrayPairs[$pair] = true;
                    }
                }

                if (!self::compare($value, $b[$key], $leftObjects, $rightObjects, $arrayPairs)) {
                    return false;
                }
            }

            return true;
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

            return self::compare(
                \get_mangled_object_vars($a),
                \get_mangled_object_vars($b),
                $leftObjects,
                $rightObjects,
                $arrayPairs,
            );
        }

        return $a === $b;
    }
}
