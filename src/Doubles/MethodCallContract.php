<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Defines the permitted argument count for one doubled method.
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
     */
    private function __construct(
        public string $type,
        public string $method,
        private int $requiredArguments,
        private ?int $maximumArguments,
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
            $method,
        );
    }

    /**
     * @throws DoublesError
     */
    public function assertPlannedArgumentCount(string $selector, int $count): void
    {
        if ($count < $this->requiredArguments) {
            throw DoublesError::tooFewPlannedArguments(
                $selector,
                $this->type,
                $this->requestedMethod,
                $count,
                $this->requiredArguments,
            );
        }

        if ($this->maximumArguments !== null && $count > $this->maximumArguments) {
            throw DoublesError::tooManyPlannedArguments(
                $selector,
                $this->type,
                $this->requestedMethod,
                $count,
                $this->maximumArguments,
            );
        }
    }

    /**
     * @throws DoublesError
     */
    public function assertCallArgumentCount(int $count): void
    {
        if ($count < $this->requiredArguments) {
            throw DoublesError::tooFewCallArguments(
                $this->type,
                $this->method,
                $count,
                $this->requiredArguments,
            );
        }

        if ($this->maximumArguments !== null && $count > $this->maximumArguments) {
            throw DoublesError::tooManyCallArguments(
                $this->type,
                $this->method,
                $count,
                $this->maximumArguments,
            );
        }
    }
}
