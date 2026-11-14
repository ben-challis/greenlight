<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Holds the validated constraint of one `toThrow()` matcher.
 *
 * `validationError()` checks the argument combination. Immediate and
 * temporal expectations both use it before the first call. `create()` also
 * reads the type of a throwable callback.
 *
 * `matches()` applies the constraint to a caught throwable.
 * `description()`, `expected()`, and `describeOutcome()` give the failure text.
 *
 * @internal
 */
final readonly class ThrowableConstraint
{
    /**
     * @param class-string<\Throwable> $expectedClass
     * @param (\Closure(never): mixed)|null $callback
     */
    private function __construct(
        private string $expectedClass,
        private ?\Throwable $instance,
        private ?\Closure $callback,
        private ?string $matching,
        private ?string $message,
    ) {}

    /**
     * @return non-empty-string|null
     */
    public static function validationError(mixed $throwable, mixed $matching, mixed $message): ?string
    {
        if ($throwable instanceof \Closure && ($matching !== null || $message !== null)) {
            return 'Do not specify matching: or message: when the throwable is a callback.';
        }

        if ($throwable instanceof \Throwable && ($matching !== null || $message !== null)) {
            return 'Do not specify matching: or message: when the throwable argument is a Throwable instance.';
        }

        if ($matching !== null && $message !== null) {
            return 'Specify matching: or message: for toThrow(). Do not specify both.';
        }

        return null;
    }

    /**
     * @template TThrowable of \Throwable
     *
     * @param class-string<TThrowable>|TThrowable|\Closure(TThrowable): void $throwable
     * @param \Closure(non-empty-string): never $usageFailure
     */
    public static function create(
        string|\Closure|\Throwable $throwable,
        ?string $matching,
        ?string $message,
        \Closure $usageFailure,
    ): self {
        $validationError = self::validationError($throwable, $matching, $message);

        if ($validationError !== null) {
            $usageFailure($validationError);
        }

        return match (true) {
            $throwable instanceof \Closure => new self(
                self::callbackThrowableClass($throwable, $usageFailure),
                null,
                $throwable,
                null,
                null,
            ),
            $throwable instanceof \Throwable => new self($throwable::class, $throwable, null, null, null),
            default => new self($throwable, null, null, $matching, $message),
        };
    }

    /**
     * Applies the constraint. With negation, an expectation failure in the
     * throwable callback means no match. Without negation, that failure
     * propagates.
     *
     * @param \Closure(string, string): bool $matchPattern
     * @param \Closure(non-empty-string): never $usageFailure
     *
     * @throws ExpectationFailed
     */
    public function matches(
        ?\Throwable $thrown,
        bool $negated,
        \Closure $matchPattern,
        \Closure $usageFailure,
    ): bool {
        $matched = $this->instance instanceof \Throwable
            ? $thrown === $this->instance
            : $thrown instanceof $this->expectedClass;

        if (!$matched || !$thrown instanceof \Throwable) {
            return false;
        }

        if ($this->matching !== null) {
            return $matchPattern($this->matching, $thrown->getMessage());
        }

        if ($this->callback instanceof \Closure) {
            try {
                $result = ($this->callback)($thrown); // @phpstan-ignore argument.type (Reflection confirms that the callback accepts the matched throwable.)
            } catch (ExpectationFailed $failure) {
                if (!$negated) {
                    throw $failure;
                }

                return false;
            }

            if ($result !== null) {
                $usageFailure(\sprintf(
                    'Return void from the throwable callback for toThrow(). It returned %s.',
                    \get_debug_type($result),
                ));
            }
        }

        return $this->message === null || $thrown->getMessage() === $this->message;
    }

    /** @return non-empty-string */
    public function description(ValueRenderer $renderer): string
    {
        $description = $this->instance instanceof \Throwable
            ? 'to throw the exact ' . $this->expectedClass . ' instance'
            : 'to throw ' . $this->expectedClass;

        return $description . match (true) {
            $this->matching !== null => ' with message matching ' . $this->matching,
            $this->callback instanceof \Closure => ' and satisfy the throwable callback',
            $this->message !== null => ' with message ' . $renderer->render($this->message),
            default => '',
        };
    }

    public function expected(): string
    {
        return $this->instance instanceof \Throwable
            ? 'the exact ' . $this->expectedClass . ' instance'
            : $this->expectedClass;
    }

    public static function describeOutcome(?\Throwable $thrown, ValueRenderer $renderer): string
    {
        return $thrown instanceof \Throwable
            ? \sprintf(
                'a callable that threw %s with message %s',
                $thrown::class,
                $renderer->render($thrown->getMessage()),
            )
            : 'a callable that did not throw';
    }

    /**
     * Returns the Throwable class declared by a throwable callback.
     *
     * @param \Closure(never): mixed $callback
     * @param \Closure(non-empty-string): never $usageFailure
     *
     * @return class-string<\Throwable>
     */
    private static function callbackThrowableClass(\Closure $callback, \Closure $usageFailure): string
    {
        $reflection = new \ReflectionFunction($callback);
        $parameters = $reflection->getParameters();
        $parameter = $parameters[0] ?? null;

        if (!$parameter instanceof \ReflectionParameter
            || $parameter->isVariadic()
            || $reflection->getNumberOfRequiredParameters() > 1
        ) {
            $usageFailure('Give the throwable callback for toThrow() one typed Throwable argument.');
        }

        if ($parameter->isPassedByReference()) {
            $usageFailure('Pass the throwable callback argument for toThrow() by value.');
        }

        $returnType = $reflection->getReturnType();

        if ($returnType instanceof \ReflectionType
            && (!$returnType instanceof \ReflectionNamedType
                || ($returnType->getName() !== 'void' && $returnType->getName() !== 'never'))
        ) {
            $usageFailure(\sprintf(
                'Return void from the throwable callback for toThrow(). Its return type is %s.',
                self::renderReflectionType($returnType),
            ));
        }

        $type = $parameter->getType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || $type->allowsNull()) {
            $usageFailure(\sprintf(
                'Declare one named, non-null Throwable parameter type for the toThrow() callback. Its parameter type is %s.',
                $type instanceof \ReflectionType ? self::renderReflectionType($type) : 'missing',
            ));
        }

        $scope = $reflection->getClosureScopeClass();
        $parent = $scope?->getParentClass();
        $throwable = match ($type->getName()) {
            'self', 'static' => $scope?->getName() ?? $type->getName(),
            'parent' => $parent instanceof \ReflectionClass ? $parent->getName() : $type->getName(),
            default => $type->getName(),
        };

        if (!\is_a($throwable, \Throwable::class, true)) {
            $usageFailure(\sprintf(
                'Declare a Throwable parameter type for the toThrow() callback. Its parameter type is %s.',
                $type->getName(),
            ));
        }

        return $throwable;
    }

    private static function renderReflectionType(\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionUnionType) {
            return \implode('|', \array_map(self::renderReflectionType(...), $type->getTypes()));
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return \implode('&', \array_map(self::renderReflectionType(...), $type->getTypes()));
        }

        if (!$type instanceof \ReflectionNamedType) {
            return 'unknown'; // @codeCoverageIgnore
        }

        return ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' : '')
            . $type->getName();
    }
}
