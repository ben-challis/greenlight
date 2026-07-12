<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Registration of one harness service: the exact type tests inject, its
 * scope, and the factory producing it.
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
