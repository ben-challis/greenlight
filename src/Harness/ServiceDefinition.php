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
     * @template T of object
     *
     * @param class-string<T> $type
     * @param \Closure(): T $factory
     */
    public function __construct(
        public string $type,
        public Scope $scope,
        public \Closure $factory,
    ) {}
}
