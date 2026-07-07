<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * A misuse of the doubles API: doubling a type outside the supported
 * boundary, planning a method that cannot be intercepted, interacting with
 * a double in a way its kind forbids, or relying on a return value that was
 * never configured. These are authoring errors, not expectation failures,
 * so the test errors rather than fails.
 *
 * @internal
 */
final class DoublesError extends \LogicException
{
    public static function stubWasCalled(string $type, string $method): self
    {
        return new self(\sprintf(
            'The stub of "%s" was called ("%s()"). Stubs exist purely to satisfy a type '
            . 'and must never be interacted with; use mock() with explicit expectations instead.',
            $type,
            $method,
        ));
    }

    public static function returnNotConfigured(string $type, string $method): self
    {
        return new self(\sprintf(
            'The mocked call "%s::%s()" has no configured answer. Every value a mock '
            . 'returns must be stated explicitly with andReturns() or andThrows().',
            $type,
            $method,
        ));
    }

    public static function spyCannotAnswer(string $type, string $method): self
    {
        return new self(\sprintf(
            'The spy of "%s" cannot answer "%s()", which declares a return value. Spies '
            . 'only record; use mock() with explicit expectations for value-returning calls.',
            $type,
            $method,
        ));
    }
}
