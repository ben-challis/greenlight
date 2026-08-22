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
     * @param \Closure(mixed): mixed $predicate
     */
    public static function predicate(\Closure $predicate, string $description = 'predicate'): ArgumentMatcher
    {
        return new PredicateMatcher($predicate, $description);
    }

    /**
     * This matcher uses the same deep equality as `Expect::toEqual()`.
     * Use it when `with()` must compare by value instead of identity.
     */
    public static function equals(mixed $value): ArgumentMatcher
    {
        return new EqualsMatcher($value);
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
