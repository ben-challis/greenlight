<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * A registry definition has precedence over a service resolver. Greenlight
 * does not manage or dispose objects from a service resolver.
 *
 * @internal
 */
final class HarnessScopes
{
    private readonly ScopeContainer $worker;

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
        $terminalSeen = false;

        foreach ($resolvers as $resolver) {
            if ($terminalSeen) {
                throw new \InvalidArgumentException('A terminal service resolver MUST be the final resolver.');
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
            $resolution = $resolver->resolve($type, $attributes);

            if ($resolution->isUnhandled()) {
                if ($resolver instanceof TerminalServiceResolver) {
                    throw new \LogicException(\sprintf(
                        'Terminal service resolver "%s" returned an unhandled result.',
                        $resolver::class,
                    ));
                }

                continue;
            }

            if ($resolution->isFailed()) {
                throw $resolution->error();
            }

            $service = $resolution->service();

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
