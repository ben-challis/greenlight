<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

final class Argument
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * This matcher accepts all values in its position.
     */
    public static function any(): ArgumentMatcher
    {
        return new Any();
    }

    /**
     * This matcher accepts instances of the specified class or interface.
     * It also accepts values when `get_debug_type()` returns `$type`.
     *
     * @template TType of string
     *
     * @param TType $type
     *
     * @return (
     *     $type is 'array'
     *         ? ArgumentMatcher<array<array-key, mixed>>
     *         : (
     *             $type is 'bool'
     *                 ? ArgumentMatcher<bool>
     *                 : (
     *                     $type is 'float'
     *                         ? ArgumentMatcher<float>
     *                         : (
     *                             $type is 'int'
     *                                 ? ArgumentMatcher<int>
     *                                 : (
     *                                     $type is 'null'
     *                                         ? ArgumentMatcher<null>
     *                                         : (
     *                                             $type is 'string'
     *                                                 ? ArgumentMatcher<string>
     *                                                 : (
     *                                                     $type is class-string
     *                                                         ? ArgumentMatcher<new<TType>>
     *                                                         : ArgumentMatcher<mixed>
     *                                                 )
     *                                         )
     *                                 )
     *                         )
     *                 )
     *         )
     * )
     * @throws InvalidDoubleUsage
     */
    public static function type(string $type): ArgumentMatcher
    {
        return new TypeMatcher($type);
    }

    /**
     * This matcher accepts values that have every specified type.
     *
     * @return ArgumentMatcher<mixed>
     * @throws InvalidDoubleUsage
     */
    public static function intersection(string $first, string $second, string ...$rest): ArgumentMatcher
    {
        return new IntersectionTypeMatcher(self::typeNames('intersection', $first, $second, \array_values($rest)));
    }

    /**
     * This matcher accepts values that have one or more specified types.
     *
     * @return ArgumentMatcher<mixed>
     * @throws InvalidDoubleUsage
     */
    public static function union(string $first, string $second, string ...$rest): ArgumentMatcher
    {
        return new UnionTypeMatcher(self::typeNames('union', $first, $second, \array_values($rest)));
    }

    /**
     * This matcher accepts the value when the closure returns true.
     * The description identifies the constraint in failure messages.
     *
     * @template T
     *
     * @param \Closure(T): mixed $predicate
     *
     * @return ArgumentMatcher<T>
     */
    public static function predicate(\Closure $predicate, string $description = 'predicate'): ArgumentMatcher
    {
        return new PredicateMatcher($predicate, $description);
    }

    /**
     * This matcher uses the same deep equality as `Expect::toEqual()`.
     * Use it when `with()` must compare by value instead of identity.
     *
     * @template T
     *
     * @param T $value
     *
     * @return ArgumentMatcher<T>
     */
    public static function equals(mixed $value): ArgumentMatcher
    {
        return new EqualsMatcher($value);
    }

    /**
     * This matcher accepts a value when all its matchers accept the value.
     * Greenlight checks the matchers in argument order and stops after a failure.
     *
     * @template T
     *
     * @param ArgumentMatcher<T> $first
     * @param ArgumentMatcher<T> $second
     * @param ArgumentMatcher<T> ...$rest
     *
     * @return ArgumentMatcher<T>
     * @throws InvalidDoubleUsage
     */
    public static function allOf(
        ArgumentMatcher $first,
        ArgumentMatcher $second,
        ArgumentMatcher ...$rest,
    ): ArgumentMatcher {
        $matchers = [$first, $second, ...\array_values($rest)];

        if (\array_any($matchers, static fn(ArgumentMatcher $matcher): bool => $matcher instanceof ArgumentCaptor)) {
            throw InvalidDoubleUsage::compositeArgumentCaptor();
        }

        return new AllOf($matchers);
    }

    /**
     * This matcher accepts all values. It records the value when Greenlight
     * selects the related expectation for the call.
     */
    public static function captor(): ArgumentCaptor
    {
        return new ArgumentCaptor();
    }

    /**
     * @param list<string> $rest
     *
     * @return non-empty-list<string>
     * @throws InvalidDoubleUsage
     */
    private static function typeNames(string $factory, string $first, string $second, array $rest): array
    {
        $types = [$first, $second, ...\array_values($rest)];

        foreach ($types as $type) {
            if (\trim($type) === '') {
                throw InvalidDoubleUsage::invalidArgumentTypeCombination($factory);
            }
        }

        return $types;
    }
}
