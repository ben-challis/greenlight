<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * A registry definition has precedence over a service resolver. Greenlight
 * does not manage or dispose objects from a service resolver.
 *
 * Currently, the suite service scope and run service scope have the same
 * lifetime. The execution plan does not contain suite boundaries.
 *
 * @internal
 */
final class HarnessScopes
{
    private readonly ScopeContainer $run;

    private readonly ScopeContainer $suite;

    private ?ScopeContainer $class = null;

    private ?ScopeContainer $test = null;

    private bool $classServicesAllowed = true;

    /**
     * @param list<ServiceResolver> $resolvers
     */
    public function __construct(
        private readonly HarnessRegistry $registry,
        private readonly array $resolvers = [],
    ) {
        $this->run = new ScopeContainer();
        $this->suite = new ScopeContainer();
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
        $definition = $this->registry->find($type);

        if ($definition instanceof ServiceDefinition) {
            if ($definition->scope === Scope::PerClass && !$this->classServicesAllowed) {
                throw UnresolvableService::perClassServiceInParallelClass($type, $consumer);
            }

            $service = $this->containerFor($definition->scope)->get($definition);

            if (!$service instanceof $type) {
                throw UnresolvableService::factoryTypeMismatch($type, $service::class);
            }

            return $service;
        }

        foreach ($this->resolvers as $resolver) {
            $service = $resolver->resolve($type, $attributes);

            if ($service === null) {
                continue;
            }

            if (!$service instanceof $type) {
                throw UnresolvableService::resolverTypeMismatch($type, $consumer, $resolver::class, $service::class);
            }

            return $service;
        }

        throw UnresolvableService::unknownType($type, $consumer, \count($this->resolvers));
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
    public function closeRun(): array
    {
        return [...$this->suite->dispose(), ...$this->run->dispose()];
    }

    private function containerFor(Scope $scope): ScopeContainer
    {
        return match ($scope) {
            Scope::PerRun => $this->run,
            Scope::PerSuite => $this->suite,
            Scope::PerClass => $this->class ?? throw new \LogicException('No class scope is open.'),
            Scope::PerTest => $this->test ?? throw new \LogicException('No test scope is open.'),
        };
    }
}
