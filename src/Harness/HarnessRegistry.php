<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * The set of registered harness services, looked up by exact type.
 *
 * @internal
 */
final class HarnessRegistry
{
    /**
     * @var array<class-string, ServiceDefinition>
     */
    private array $definitions = [];

    /**
     * @param list<ServiceDefinition> $definitions
     */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public function register(ServiceDefinition $definition): void
    {
        if (isset($this->definitions[$definition->type])) {
            throw new \LogicException(\sprintf(
                'A harness service for %s is already registered.',
                $definition->type,
            ));
        }

        $this->definitions[$definition->type] = $definition;
    }

    /**
     * @param class-string $type
     */
    public function find(string $type): ?ServiceDefinition
    {
        return $this->definitions[$type] ?? null;
    }
}
