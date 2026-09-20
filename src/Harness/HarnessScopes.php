<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * An explicit source limits resolution to that source. Without a source,
 * service definitions have precedence over service resolvers. Type names do
 * not use letter case for identity. Greenlight does not dispose services that
 * a resolver supplies.
 *
 * @internal
 */
final class HarnessScopes
{
    /**
     * @var array<string, ServiceDefinition>
     */
    private array $definitions = [];

    /** @var array<string, array<string, ServiceDefinition>> */
    private array $namedDefinitions = [];

    /** @var array<string, ServiceResolver> */
    private array $namedResolvers = [];

    private readonly ScopeContainer $worker;

    private ?ScopeContainer $class = null;

    private ?ScopeContainer $test = null;

    private bool $classServicesAllowed = true;

    /**
     * @param list<ServiceDefinition> $definitions
     * @param list<ServiceResolver> $resolvers
     */
    public function __construct(
        array $definitions = [],
        private readonly array $resolvers = [],
    ) {
        foreach ($definitions as $definition) {
            $key = \strtolower($definition->type);

            if ($definition->source !== null) {
                if (isset($this->namedDefinitions[$definition->source][$key])) {
                    throw new \InvalidArgumentException(\sprintf(
                        'Service source "%s" already defines type "%s".',
                        $definition->source,
                        $definition->type,
                    ));
                }

                $this->namedDefinitions[$definition->source][$key] = $definition;

                continue;
            }

            if (isset($this->definitions[$key])) {
                throw new \LogicException(\sprintf(
                    'A harness service for %s is already registered.',
                    $definition->type,
                ));
            }

            $this->definitions[$key] = $definition;
        }

        $terminalSeen = false;

        foreach ($resolvers as $resolver) {
            $source = $resolver instanceof ServiceSource ? $resolver->source() : null;

            if ($source !== null) {
                if (isset($this->namedResolvers[$source])) {
                    throw new \InvalidArgumentException(\sprintf('Service source "%s" is already registered.', $source));
                }

                $this->namedResolvers[$source] = $resolver;
            }

            if ($terminalSeen) {
                throw new \InvalidArgumentException('Place a terminal service resolver last.');
            }

            $terminalSeen = $resolver instanceof TerminalServiceResolver;
        }

        $this->worker = new ScopeContainer();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     * @param non-empty-string $consumer
     * @param list<object> $attributes
     *
     * @return T
     *
     * @throws ServiceResolutionFailed when a service resolver cannot supply a valid service
     * @throws UnresolvableService
     */
    public function resolve(string $type, string $consumer, array $attributes = []): object
    {
        foreach ($attributes as $attribute) {
            if ($attribute instanceof Service && $attribute->source !== null) {
                return $this->resolveFromSource($type, $consumer, $attributes, $attribute, $attribute->source);
            }
        }

        $definition = $this->definitions[\strtolower($type)] ?? null;

        if ($definition === null) {
            foreach ($this->namedDefinitions as $definitions) {
                $candidate = $definitions[\strtolower($type)] ?? null;

                if ($candidate === null) {
                    continue;
                }

                if ($definition !== null) {
                    throw UnresolvableService::ambiguousSource($type, $consumer);
                }

                $definition = $candidate;
            }
        }

        if ($definition !== null) {
            return $this->resolveDefinition($definition, $type, $consumer);
        }

        foreach ($this->resolvers as $resolver) {
            $service = $this->resolveUsing($resolver, $type, $consumer, $attributes);

            if ($service !== null) {
                return $service;
            }
        }

        throw UnresolvableService::unknownType($type, $consumer, \count($this->resolvers));
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @param non-empty-string $consumer
     * @param list<object> $attributes
     * @param non-empty-string $source
     * @return T
     * @throws ServiceResolutionFailed
     */
    private function resolveFromSource(string $type, string $consumer, array $attributes, Service $selection, string $source): object
    {
        $definitions = $this->namedDefinitions[$source] ?? [];
        $resolver = $this->namedResolvers[$source] ?? null;

        if ($definitions === [] && $resolver === null) {
            throw UnresolvableService::unknownSource($source, $consumer);
        }

        $definition = $definitions[\strtolower($type)] ?? null;

        if ($selection->id === null && $definition !== null) {
            return $this->resolveDefinition($definition, $type, $consumer);
        }

        if ($resolver !== null) {
            $service = $this->resolveUsing($resolver, $type, $consumer, $attributes);

            if ($service !== null) {
                return $service;
            }
        }

        throw UnresolvableService::missingSourceService($source, $selection->id ?? $type, $consumer);
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @param non-empty-string $consumer
     * @return T
     * @throws UnresolvableService
     */
    private function resolveDefinition(ServiceDefinition $definition, string $type, string $consumer): object
    {
        if ($definition->scope === Scope::PerClass && !$this->classServicesAllowed) {
            throw UnresolvableService::perClassServiceInParallelClass($type, $consumer);
        }

        $service = $this->containerFor($definition->scope)->get($definition);

        if (!$service instanceof $type) {
            throw UnresolvableService::factoryTypeMismatch($type, $service);
        }

        return $service;
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @param non-empty-string $consumer
     * @param list<object> $attributes
     * @return T|null
     * @throws ServiceResolutionFailed
     */
    private function resolveUsing(ServiceResolver $resolver, string $type, string $consumer, array $attributes): ?object
    {
        $service = $resolver->resolve($type, $attributes);

        if ($service === null) {
            if ($resolver instanceof TerminalServiceResolver) {
                throw new \LogicException(\sprintf(
                    'Terminal service resolver "%s" returned null.',
                    $resolver::class,
                ));
            }

            return null;
        }

        if (!$service instanceof $type) {
            throw UnresolvableService::resolverTypeMismatch($type, $consumer, $resolver::class, $service);
        }

        return $service;
    }

    public function openClass(bool $allowPerClassServices = true): void
    {
        $this->class = new ScopeContainer();
        $this->classServicesAllowed = $allowPerClassServices;
    }

    /**
     * @return list<\Throwable>
     */
    public function closeClass(): array
    {
        $failures = $this->class?->dispose() ?? [];
        $this->class = null;
        $this->classServicesAllowed = true;

        return $failures;
    }

    public function openTest(): void
    {
        $this->test = new ScopeContainer();
    }

    /**
     * @return list<\Throwable>
     */
    public function closeTest(): array
    {
        $failures = $this->test?->dispose() ?? [];
        $this->test = null;

        return $failures;
    }

    /**
     * @return list<\Throwable>
     */
    public function closeWorker(): array
    {
        return $this->worker->dispose();
    }

    private function containerFor(Scope $scope): ScopeContainer
    {
        return match ($scope) {
            Scope::PerWorker => $this->worker,
            Scope::PerClass => $this->class ?? throw new \LogicException('No class scope is open.'),
            Scope::PerTest => $this->test ?? throw new \LogicException('No test scope is open.'),
        };
    }
}
