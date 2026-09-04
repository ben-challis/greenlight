<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Identifies incorrect use of the doubles API. Examples include an
 * unsupported type or a method that `Doubles` cannot intercept. Other examples
 * are a prohibited interaction or a return value without a configured
 * result.
 *
 * These conditions are errors in the test code, not expectation failures.
 * Thus, Greenlight reports the test as an error.
 */
final class InvalidDoubleUsage extends \LogicException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function stubWasCalled(string $type, string $method): self
    {
        return new self(\sprintf(
            'Code called "%s()" on the stub of "%s". Stubs only satisfy a type. '
            . 'Use mock() with explicit expectations for interactions.',
            $method,
            $type,
        ));
    }

    public static function returnNotConfigured(string $type, string $method): self
    {
        return new self(\sprintf(
            'The mock call "%s::%s()" has no configured answer. Configure each returned '
            . 'value with andReturns() or andThrows().',
            $type,
            $method,
        ));
    }

    public static function spyCannotAnswer(string $type, string $method): self
    {
        return new self(\sprintf(
            'The spy of "%s" cannot supply a value for "%s()". Spies only record '
            . 'interactions. Use mock() with explicit expectations for calls that return values.',
            $type,
            $method,
        ));
    }

    public static function noSuchMethod(string $type, string $method): self
    {
        return new self(\sprintf('%s has no method %s(). Doubles cannot plan it.', $type, $method));
    }

    public static function noSuchRecordedMethod(string $type, string $method): self
    {
        return new self(\sprintf('%s has no method %s(). Doubles cannot inspect calls to it.', $type, $method));
    }

    public static function staticMethod(string $type, string $method): self
    {
        return new self(\sprintf('%s::%s() is static. Doubles cannot intercept static methods.', $type, $method));
    }

    public static function neverMethodRequiresThrow(string $type, string $method): self
    {
        return new self(\sprintf('%s::%s() declares never. Configure it with andThrows().', $type, $method));
    }

    public static function methodNotPublic(string $type, string $method): self
    {
        return new self(\sprintf('%s::%s() is not public. Doubles cannot plan it.', $type, $method));
    }

    public static function finalMethod(string $type, string $method): self
    {
        return new self(\sprintf('%s::%s() is final. Doubles cannot intercept it. Use an interface instead.', $type, $method));
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
        return new self(\sprintf('%s is an enum. Doubles does not support enums. Use an interface that the enum implements.', $type));
    }

    public static function cannotDoubleReadonly(string $type): self
    {
        return new self(\sprintf('%s is a readonly class. Doubles v1 does not support readonly classes. Use an interface instead.', $type));
    }

    public static function cannotDoubleFinal(string $type): self
    {
        return new self(\sprintf('%s is final. Doubles cannot create a proxy subclass. Use an interface instead.', $type));
    }

    public static function cannotDoubleTrait(string $type): self
    {
        return new self(\sprintf('%s is a trait. Doubles cannot create a proxy for a trait. Use a class or interface that uses it.', $type));
    }

    public static function notDoubleable(string $type): self
    {
        return new self(\sprintf('Doubles cannot load %s as a class or interface.', $type));
    }

    public static function attachHandlerCollision(string $class): self
    {
        return new self(\sprintf('%s declares __greenlightAttachHandler(). This method conflicts with the proxy handler method.', $class));
    }

    public static function handlerPropertyCollision(string $class): self
    {
        return new self(\sprintf('%s declares $__greenlightHandler. This property conflicts with the proxy handler storage property.', $class));
    }

    public static function defaultValueNotReproducible(string $parameter, string $class, string $method): self
    {
        return new self(\sprintf(
            'Doubles cannot reproduce the default value of parameter $%s from %s::%s() in a proxy.',
            $parameter,
            $class,
            $method,
        ));
    }

    public static function defaultConstantUnresolvable(string $parameter): self
    {
        return new self(\sprintf('Doubles cannot resolve the default constant of parameter $%s.', $parameter));
    }

    public static function objectDefaultNotReproducible(string $parameter, string $class, string $method): self
    {
        return new self(\sprintf(
            'Doubles cannot reproduce the object default of parameter $%s from %s::%s() in a proxy. Use an interface without object defaults instead.',
            $parameter,
            $class,
            $method,
        ));
    }

    public static function proxyDirectoryNotCreated(string $directory, ?string $reason = null): self
    {
        return new self(\sprintf(
            'Doubles could not create the proxy directory %s%s.',
            $directory,
            $reason === null ? '' : ': ' . $reason,
        ));
    }

    public static function proxyFileNotWritten(string $file, \Throwable $cause): self
    {
        return new self(\sprintf('Doubles could not write the proxy file %s.', $file), $cause);
    }

    public static function proxyFileNotLoaded(string $file, ?\Throwable $cause = null): self
    {
        return new self(\sprintf('Doubles could not load the proxy file %s. Delete the file and retry.', $file), $cause);
    }

    public static function workingDirectoryUnresolved(): self
    {
        return new self('Doubles could not resolve the working directory. Pass a proxy directory explicitly.');
    }

    public static function foreignDouble(string $class): self
    {
        return new self(\sprintf('This Doubles factory did not create the %s instance.', $class));
    }

    public static function invalidTimes(int $count): self
    {
        return new self(\sprintf('times(%d) requires a count of zero or more.', $count));
    }

    public static function invalidAtLeast(int $count): self
    {
        return new self(\sprintf('atLeast(%d) requires a count of one or more.', $count));
    }

    /**
     * @param class-string $type
     */
    public static function tooFewPlannedArguments(
        string $selector,
        string $type,
        string $method,
        int $actual,
        int $required,
    ): self {
        return new self(\sprintf(
            '%s() supplies %s for %s::%s(), but the method requires %s.',
            $selector,
            self::argumentCount($actual),
            $type,
            $method,
            self::argumentCount($required),
        ));
    }

    /**
     * @param class-string $type
     */
    public static function tooManyPlannedArguments(
        string $selector,
        string $type,
        string $method,
        int $actual,
        int $maximum,
    ): self {
        return new self(\sprintf(
            '%s() supplies %s for %s::%s(), but the method accepts at most %s.',
            $selector,
            self::argumentCount($actual),
            $type,
            $method,
            self::argumentCount($maximum),
        ));
    }

    /**
     * @param class-string $type
     */
    public static function incompatiblePlannedArgumentMatcher(
        string $selector,
        string $type,
        string $method,
        int $position,
        string $matcherType,
        string $parameter,
        string $parameterType,
    ): self {
        return new self(\sprintf(
            'The matcher in %s() argument %d accepts "%s", but parameter "$%s" of "%s::%s()" requires "%s".',
            $selector,
            $position,
            $matcherType,
            $parameter,
            $type,
            $method,
            $parameterType,
        ));
    }

    /**
     * @param class-string $type
     */
    public static function tooFewCallArguments(string $type, string $method, int $actual, int $required): self
    {
        return new self(\sprintf(
            'The call to %s::%s() supplies %s, but the method requires %s.',
            $type,
            $method,
            self::argumentCount($actual),
            self::argumentCount($required),
        ));
    }

    /**
     * @param class-string $type
     */
    public static function tooManyCallArguments(string $type, string $method, int $actual, int $maximum): self
    {
        return new self(\sprintf(
            'The call to %s::%s() supplies %s, but the method accepts at most %s.',
            $type,
            $method,
            self::argumentCount($actual),
            self::argumentCount($maximum),
        ));
    }

    public static function conflictingAnswers(string $method): self
    {
        return new self(\sprintf(
            'The expectation on %s() already has an answer. Configure exactly one of '
            . 'andReturns(), andReturnsSequence(), andReturnsUsing(), or andThrows().',
            $method,
        ));
    }

    public static function emptySequence(string $method): self
    {
        return new self(\sprintf('andReturnsSequence() on %s() requires at least one value.', $method));
    }

    public static function sequenceExhausted(string $method, int $count): self
    {
        return new self(\sprintf(
            'The return sequence for %s() has no value after %s. Add values or use a stricter call count.',
            $method,
            MethodExpectation::timesPhrase($count),
        ));
    }

    public static function nothingCaptured(): self
    {
        return new self('The captor has no value. No matched call supplied a value.');
    }

    public static function invalidCaptorPosition(int $position): self
    {
        return new self(\sprintf('captureArgument(%d) requires a position of zero or more.', $position));
    }

    public static function invalidArgumentType(): self
    {
        return new self('Argument::type() requires a type name that contains a non-space character.');
    }

    public static function invalidArgumentTypeCombination(string $factory): self
    {
        return new self(\sprintf(
            'Argument::%s() requires type names that contain a non-space character.',
            $factory,
        ));
    }

    public static function compositeArgumentCaptor(): self
    {
        return new self('Argument::allOf() does not accept a captor. Put the captor directly in with().');
    }

    private static function argumentCount(int $count): string
    {
        return \sprintf('%d argument%s', $count, $count === 1 ? '' : 's');
    }
}
