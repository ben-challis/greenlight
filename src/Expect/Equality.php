<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Internal\Php\ErrorTrap;

/** @internal */
final class Equality
{
    private const int ARRAY_CYCLE_CHECK_DEPTH = 64;

    private const string STATE_PROPERTIES = 'properties';

    private const string STATE_SERIALIZE = 'serialize';

    private const string STATE_HEAP = 'heap';

    private const string STATE_OBJECT_STORAGE = 'object-storage';

    private const string STATE_DECLARED_PROPERTIES = 'declared-properties';

    /**
     * Native classes that keep all their state in the property table.
     */
    private const array PROPERTY_ONLY_NATIVE_CLASSES = [
        \stdClass::class,
        \__PHP_Incomplete_Class::class,
    ];

    /**
     * The state source for each class. A declared-properties source also
     * lists the property-table keys that must exist.
     *
     * @var array<class-string, array{string, list<string>}>
     */
    private static array $stateSources = [];

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
        self::requireAcyclicArrays($a);
        self::requireAcyclicArrays($b);
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
            \asort($keys, \SORT_STRING);
            $canonical = \array_map(static fn(int $index): mixed => $canonical[$index], \array_keys($keys));
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
            $properties = self::comparableState($value, $seen);

            if ($properties === null) {
                return $value::class . '#' . $id;
            }

            $parts = [];
            self::requireAcyclicArrays($properties);
            \ksort($properties, \SORT_STRING);

            foreach ($properties as $name => $item) {
                $parts[] = \var_export($name, true) . '=>' . self::sortKey($item, $seen);
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
        int $arrayDepth = 0,
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

            if ($arrayDepth === self::ARRAY_CYCLE_CHECK_DEPTH) {
                self::requireAcyclicArrays($a);
                self::requireAcyclicArrays($b);
                $arrayDepth = -1;
            }

            $nextArrayDepth = $arrayDepth < 0 ? -1 : $arrayDepth + 1;

            return \array_all(
                $a,
                static function ($value, $key) use ($b, &$leftObjects, &$rightObjects, $nextArrayDepth): bool {
                    return \array_key_exists($key, $b)
                        && self::compare(
                            $value,
                            $b[$key],
                            $leftObjects,
                            $rightObjects,
                            $nextArrayDepth,
                        );
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

            $leftState = self::comparableState($a, [$leftId]);
            $rightState = self::comparableState($b, [$rightId]);

            if ($leftState === null || $rightState === null) {
                return false;
            }

            return self::compare($leftState, $rightState, $leftObjects, $rightObjects);
        }

        return $a === $b;
    }

    /**
     * Returns the state that equality compares for an object. The property
     * table does not contain the state of most native classes. For example,
     * `ArrayObject` and `GMP` keep their values in native storage. A class with
     * a native ancestor therefore adds a native state source to its properties.
     *
     * @param list<int> $seen Object IDs already in the conversion stack,
     *   including the object itself. A storage can reach itself through its
     *   entries, and this list stops the cycle when the entries get a sort key.
     *
     * @return array<array-key, mixed>|null Null when the object has no
     *   comparable state. Such objects are equal only to themselves.
     */
    private static function comparableState(object $value, array $seen): ?array
    {
        [$source, $requiredKeys] = self::$stateSources[$value::class] ??= self::stateSource($value::class);
        $properties = \get_mangled_object_vars($value);

        try {
            return match ($source) {
                self::STATE_PROPERTIES => $properties,
                self::STATE_SERIALIZE => [
                    'properties' => $properties,
                    'native' => self::serializedState($value),
                ],
                self::STATE_OBJECT_STORAGE => [
                    'properties' => $properties,
                    'native' => self::storageEntries($value, $seen),
                ],
                self::STATE_HEAP => [
                    'properties' => $properties,
                    'native' => self::heapElements($value),
                ],
                default => $requiredKeys !== []
                    && \array_all($requiredKeys, static fn(string $key): bool => \array_key_exists($key, $properties))
                        ? $properties
                        : null,
            };
        } catch (\Throwable) {
            // A native state source that cannot be read gives no evidence of
            // equality.
            return null;
        }
    }

    /**
     * @param class-string $class
     *
     * @return array{string, list<string>}
     */
    private static function stateSource(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $native = [];

        for ($ancestor = $reflection; $ancestor instanceof \ReflectionClass; $ancestor = $ancestor->getParentClass()) {
            if ($ancestor->isInternal()) {
                $native[] = $ancestor;
            }
        }

        if ($native === [] || \in_array($native[0]->getName(), self::PROPERTY_ONLY_NATIVE_CLASSES, true)) {
            return [self::STATE_PROPERTIES, []];
        }

        if ($class === \SplObjectStorage::class || $reflection->isSubclassOf(\SplObjectStorage::class)) {
            return [self::STATE_OBJECT_STORAGE, []];
        }

        if ($reflection->isSubclassOf(\SplHeap::class) || $reflection->isSubclassOf(\SplPriorityQueue::class)
            || $class === \SplPriorityQueue::class
        ) {
            return [self::STATE_HEAP, []];
        }

        if ($reflection->hasMethod('__serialize')) {
            return [self::STATE_SERIALIZE, []];
        }

        // Some native classes, such as exceptions, keep all state in declared
        // properties. A native class without such properties, or with
        // properties outside the property table, has no comparable state.
        $requiredKeys = [];

        foreach ($native as $ancestor) {
            foreach ($ancestor->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $ancestor->getName()) {
                    continue;
                }

                $requiredKeys[] = match (true) {
                    $property->isPrivate() => "\0" . $ancestor->getName() . "\0" . $property->getName(),
                    $property->isProtected() => "\0*\0" . $property->getName(),
                    default => $property->getName(),
                };
            }
        }

        return [self::STATE_DECLARED_PROPERTIES, $requiredKeys];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function serializedState(object $value): array
    {
        $serialize = [$value, '__serialize'];

        if (!\is_callable($serialize)) {
            throw new \LogicException('The object has no public __serialize() method.');
        }

        $state = $serialize();

        if (!\is_array($state)) {
            throw new \LogicException('__serialize() did not return an array.');
        }

        return $state;
    }

    /**
     * Returns the object and data pairs in a canonical order. A storage is a
     * set, so the attach order has no effect on equality.
     *
     * @param list<int> $seen Object IDs already in the conversion stack
     *
     * @return list<array{object, mixed}>
     */
    private static function storageEntries(object $value, array $seen): array
    {
        if (!$value instanceof \SplObjectStorage) {
            throw new \LogicException('An object storage must extend SplObjectStorage.'); // @codeCoverageIgnore
        }

        // Iterate a copy, so the iterator position of the original stays.
        $copy = clone $value;
        $entries = [];
        $keys = [];

        foreach ($copy as $object) {
            $entry = [$object, $copy->getInfo()];
            $entries[] = $entry;
            $keys[] = self::sortKey($entry, $seen);
        }

        \asort($keys, \SORT_STRING);

        return \array_map(static fn(int $index): array => $entries[$index], \array_keys($keys));
    }

    /**
     * Reads a copy, so the original heap keeps its elements.
     *
     * @return list<mixed>
     */
    private static function heapElements(object $value): array
    {
        $copy = clone $value;

        if ($copy instanceof \SplPriorityQueue) {
            $copy->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        }

        if (!$copy instanceof \Traversable) {
            throw new \LogicException('A heap must be traversable.'); // @codeCoverageIgnore
        }

        return \iterator_to_array($copy, false);
    }

    private static function requireAcyclicArrays(mixed $value): void
    {
        if (!\is_array($value)) {
            return;
        }

        // Native traversal sees cycles even after PHP unwraps their reference
        // containers. Delay ordinary comparisons so early mismatches do not
        // scan an otherwise unused array graph. A checked subtree stays checked.
        ErrorTrap::run(static fn(): int => \count($value, \COUNT_RECURSIVE), $warning);

        if ($warning !== null) {
            throw new \InvalidArgumentException(
                'Equality matchers do not support cyclic arrays. Compare selected acyclic values instead.',
            );
        }
    }
}
