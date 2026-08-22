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
     * @template T of object
     *
     * @param class-string<T>|string $type
     *
     * @return ($type is class-string<T> ? ArgumentMatcher<T> : ArgumentMatcher<mixed>)
     * @throws InvalidDoubleUsage
     */
    public static function type(string $type): ArgumentMatcher
    {
        return new TypeMatcher($type);
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
}
