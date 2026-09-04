<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Defines one harness service. It contains the exact injected type, service
 * scope, and factory.
 */
final readonly class ServiceDefinition
{
    /**
     * @var class-string
     */
    public string $type;

    /**
     * @template T of object
     *
     * @param class-string<T>|'' $type
     * @param \Closure(): T $factory
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $type,
        public Scope $scope,
        public \Closure $factory,
    ) {
        if ($type === '') {
            throw new \InvalidArgumentException('Harness service type cannot be empty.');
        }

        $this->type = $type;
    }
}
