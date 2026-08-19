<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Stores registered harness services by their exact PHP types. Type names do
 * not use letter case for identity.
 *
 * @internal
 */
final class HarnessRegistry
{
    /**
     * @var array<string, ServiceDefinition>
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
        $key = $this->typeKey($definition->type);

        if (isset($this->definitions[$key])) {
            throw new \LogicException(\sprintf(
                'A harness service for %s is already registered.',
                $definition->type,
            ));
        }

        $this->definitions[$key] = $definition;
    }

    /**
     * @param class-string $type
     */
    public function find(string $type): ?ServiceDefinition
    {
        return $this->definitions[$this->typeKey($type)] ?? null;
    }

    private function typeKey(string $type): string
    {
        return \strtolower($type);
    }
}
