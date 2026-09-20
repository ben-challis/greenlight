<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Defines one harness service. It contains the exact injected type, service
 * scope, factory, and optional source name.
 */
final readonly class ServiceDefinition
{
    /**
     * @var class-string
     */
    public string $type;

    /** @var non-empty-string|null */
    public ?string $source;

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
        ?string $source = null,
    ) {
        if ($type === '') {
            throw new \InvalidArgumentException('Harness service type cannot be empty.');
        }

        if ($source === '') {
            throw new \InvalidArgumentException('Service source must not be empty.');
        }

        $this->type = $type;
        $this->source = $source;
    }
}
