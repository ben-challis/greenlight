<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Defines the permitted arguments for one doubled method.
 *
 * @internal
 *
 * @template TTarget of object = object
 * @template TMethod of non-empty-string = non-empty-string
 */
final readonly class MethodCallContract
{
    /**
     * @param class-string<TTarget> $type
     * @param TMethod $requestedMethod
     * @param list<\ReflectionParameter> $parameters
     */
    private function __construct(
        public string $type,
        public string $method,
        private int $requiredArguments,
        private ?int $maximumArguments,
        private array $parameters,
        public string $requestedMethod,
    ) {}

    /**
     * @template TRequestedTarget of object
     * @template TRequestedMethod of non-empty-string
     *
     * @param class-string<TRequestedTarget> $type
     * @param TRequestedMethod $method
     *
     * @return self<TRequestedTarget, TRequestedMethod>
     */
    public static function from(string $type, string $method): self
    {
        return self::fromReflection($type, $method, new \ReflectionMethod($type, $method));
    }

    /**
     * @template TRequestedTarget of object
     * @template TRequestedMethod of non-empty-string
     *
     * @param class-string<TRequestedTarget> $type
     * @param TRequestedMethod $method
     *
     * @return self<TRequestedTarget, TRequestedMethod>
     */
    public static function fromReflection(string $type, string $method, \ReflectionMethod $reflection): self
    {
        return new self(
            $type,
            $reflection->getName(),
            $reflection->getNumberOfRequiredParameters(),
            $reflection->isVariadic() ? null : $reflection->getNumberOfParameters(),
            \array_values($reflection->getParameters()),
            $method,
        );
    }

    /**
     * @throws InvalidDoubleUsage
     */
    public function assertPlannedArgumentCount(string $selector, int $count): void
    {
        if ($count < $this->requiredArguments) {
            throw InvalidDoubleUsage::tooFewPlannedArguments(
                $selector,
                $this->type,
                $this->requestedMethod,
                $count,
                $this->requiredArguments,
            );
        }

        if ($this->maximumArguments !== null && $count > $this->maximumArguments) {
            throw InvalidDoubleUsage::tooManyPlannedArguments(
                $selector,
                $this->type,
                $this->requestedMethod,
                $count,
                $this->maximumArguments,
            );
        }
    }

    /**
     * Checks the planned arguments against the declared parameters. Then it
     * converts the arguments to the values that a matching call records.
     *
     * @param list<mixed> $arguments
     *
     * @return list<mixed>
     * @throws InvalidDoubleUsage
     */
    public function plannedArguments(string $selector, array $arguments): array
    {
        $this->assertPlannedArgumentCount($selector, \count($arguments));

        foreach ($arguments as $position => $argument) {
            $parameter = $this->parameters[\min($position, \count($this->parameters) - 1)];
            $reflectionType = $parameter->getType();

            if ($reflectionType === null) {
                continue;
            }

            $parameterType = ArgumentType::fromReflection($reflectionType, $parameter->getDeclaringClass());

            if ($argument instanceof ArgumentMatcher) {
                if ($argument instanceof TypedArgumentMatcher) {
                    $this->assertMatcherCompatible($selector, $position, $argument, $parameter, $parameterType);
                }

                continue;
            }

            $converted = $this->convert($argument, $parameterType);

            if (!$parameterType->mayAccept($converted)) {
                throw InvalidDoubleUsage::incompatiblePlannedArgumentValue(
                    $selector,
                    $this->type,
                    $this->requestedMethod,
                    $position + 1,
                    \get_debug_type($argument),
                    $parameter->getName(),
                    $parameterType->describe(),
                );
            }

            $arguments[$position] = $converted;
        }

        return $arguments;
    }

    /**
     * @throws InvalidDoubleUsage
     */
    private function assertMatcherCompatible(
        string $selector,
        int $position,
        TypedArgumentMatcher $matcher,
        \ReflectionParameter $parameter,
        ArgumentType $parameterType,
    ): void {
        $argumentType = $matcher->argumentType();

        if ($argumentType?->overlaps($parameterType) !== false) {
            return;
        }

        throw InvalidDoubleUsage::incompatiblePlannedArgumentMatcher(
            $selector,
            $this->type,
            $this->requestedMethod,
            $position + 1,
            $argumentType->describe(),
            $parameter->getName(),
            $parameterType->describe(),
        );
    }

    /**
     * PHP converts an integer argument to float when the parameter does not
     * accept `int`. This conversion also applies with strict types.
     */
    private function convert(mixed $value, ArgumentType $parameterType): mixed
    {
        if (\is_int($value) && !$parameterType->accepts($value) && $parameterType->accepts((float) $value)) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * @throws InvalidDoubleUsage
     */
    public function assertCallArgumentCount(int $count): void
    {
        if ($count < $this->requiredArguments) {
            throw InvalidDoubleUsage::tooFewCallArguments(
                $this->type,
                $this->method,
                $count,
                $this->requiredArguments,
            );
        }

        if ($this->maximumArguments !== null && $count > $this->maximumArguments) {
            throw InvalidDoubleUsage::tooManyCallArguments(
                $this->type,
                $this->method,
                $count,
                $this->maximumArguments,
            );
        }
    }
}
