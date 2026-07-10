<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * A misuse of the doubles API: doubling a type outside the supported
 * boundary, planning a method that cannot be intercepted, interacting with
 * a double in a way its kind forbids, or relying on a return value that was
 * never configured.
 *
 * These are authoring errors, not expectation failures, so the test errors
 * rather than fails.
 *
 * @internal
 */
final class DoublesError extends \LogicException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

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

    public static function noSuchMethod(string $type, string $method): self
    {
        return new self(\sprintf('%s has no method %s(), so it cannot be planned.', $type, $method));
    }

    public static function staticMethod(string $type, string $method): self
    {
        return new self(\sprintf('%s::%s() is static; static methods cannot be doubled.', $type, $method));
    }

    public static function methodNotPublic(string $type, string $method): self
    {
        return new self(\sprintf('%s::%s() is not public, so it cannot be planned on a double.', $type, $method));
    }

    public static function finalMethod(string $type, string $method): self
    {
        return new self(\sprintf('%s::%s() is final and cannot be intercepted. Double an interface instead.', $type, $method));
    }

    public static function unsupportedReflectionType(string $typeClass): self
    {
        return new self(\sprintf('Unsupported reflection type %s.', $typeClass));
    }

    public static function parentTypeWithoutParent(string $context): self
    {
        return new self(\sprintf('%s uses the parent type but has no parent class.', $context));
    }

    public static function unsupportedNestedReflectionType(string $typeClass): self
    {
        return new self(\sprintf('Unsupported nested reflection type %s.', $typeClass));
    }

    public static function cannotDoubleEnum(string $type): self
    {
        return new self(\sprintf('%s is an enum and cannot be doubled. Double an interface it implements instead.', $type));
    }

    public static function cannotDoubleReadonly(string $type): self
    {
        return new self(\sprintf('%s is a readonly class and cannot be doubled in v1. Double an interface instead.', $type));
    }

    public static function cannotDoubleFinal(string $type): self
    {
        return new self(\sprintf('%s is final and cannot be doubled. Double an interface instead; that boundary is deliberate.', $type));
    }

    public static function cannotDoubleTrait(string $type): self
    {
        return new self(\sprintf('%s is a trait and cannot be doubled. Double a class or interface using it instead.', $type));
    }

    public static function notDoubleable(string $type): self
    {
        return new self(\sprintf('%s is not a loadable class or interface, so it cannot be doubled.', $type));
    }

    public static function attachHandlerCollision(string $class): self
    {
        return new self(\sprintf('%s declares __greenlightAttachHandler(), which collides with the proxy plumbing.', $class));
    }

    public static function defaultValueNotReproducible(string $parameter, string $class, string $method): self
    {
        return new self(\sprintf(
            'The default value of parameter $%s of %s::%s() cannot be reproduced in a proxy.',
            $parameter,
            $class,
            $method,
        ));
    }

    public static function defaultConstantUnresolvable(string $parameter): self
    {
        return new self(\sprintf('The default constant of parameter $%s could not be resolved.', $parameter));
    }

    public static function objectDefaultNotReproducible(string $parameter, string $class, string $method): self
    {
        return new self(\sprintf(
            'The object default of parameter $%s of %s::%s() cannot be reproduced in a proxy. Double an interface without object defaults instead.',
            $parameter,
            $class,
            $method,
        ));
    }

    public static function proxyDirectoryNotCreated(string $directory): self
    {
        return new self(\sprintf('The proxy directory %s could not be created.', $directory));
    }

    public static function proxyFileNotWritten(string $file): self
    {
        return new self(\sprintf('The proxy file %s could not be written.', $file));
    }

    public static function workingDirectoryUnresolved(): self
    {
        return new self('The working directory could not be resolved; pass a proxy directory explicitly.');
    }

    public static function foreignDouble(string $class): self
    {
        return new self(\sprintf('The given %s instance was not created by this Doubles factory.', $class));
    }

    public static function invalidTimes(int $count): self
    {
        return new self(\sprintf('times(%d) is invalid: the count must be zero or more.', $count));
    }

    public static function invalidAtLeast(int $count): self
    {
        return new self(\sprintf('atLeast(%d) is invalid: the count must be one or more.', $count));
    }

    public static function conflictingAnswers(string $method): self
    {
        return new self(\sprintf(
            'The expectation on %s() already has an answer. Configure exactly one of '
            . 'andReturns(), andReturnsSequence(), andReturnsUsing(), or andThrows() per expectation.',
            $method,
        ));
    }

    public static function emptySequence(string $method): self
    {
        return new self(\sprintf('andReturnsSequence() on %s() needs at least one value.', $method));
    }

    public static function sequenceExhausted(string $method, int $count): self
    {
        return new self(\sprintf(
            'The return sequence of %s() is exhausted after %s. Plan more values or a stricter call count.',
            $method,
            MethodExpectation::timesPhrase($count),
        ));
    }

    public static function nothingCaptured(): self
    {
        return new self('The captor has not captured a value yet: no matched call has fed it.');
    }

    public static function invalidCaptorPosition(int $position): self
    {
        return new self(\sprintf('captureArgument(%d) is invalid: the position must be zero or more.', $position));
    }
}
