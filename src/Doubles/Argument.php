<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

final class Argument
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * Matches any value in its position.
     */
    public static function any(): Any
    {
        return new Any();
    }

    /**
     * Matches instances of the given class or interface, or values whose
     * builtin type name (as reported by get_debug_type()) equals $type.
     *
     * @param non-empty-string $type
     */
    public static function type(string $type): ArgumentMatcher
    {
        return new TypeMatcher($type);
    }

    /**
     * Matches when the closure returns true for the value. The description
     * names the constraint in failure messages.
     */
    public static function predicate(\Closure $predicate, string $description = 'predicate'): ArgumentMatcher
    {
        return new PredicateMatcher($predicate, $description);
    }

    /**
     * Matches by the same deep equality a bare with() value uses; this form
     * exists to state that comparison explicitly.
     */
    public static function equals(mixed $value): ArgumentMatcher
    {
        return new EqualsMatcher($value);
    }

    /**
     * Matches any value and records it on every call the surrounding
     * expectation actually answers.
     */
    public static function captor(): ArgumentCaptor
    {
        return new ArgumentCaptor();
    }
}
