<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Stores services for one service scope.
 *
 * dispose() disposes the services in reverse creation order.
 *
 * If the service type is a final class that supports a lazy proxy, Greenlight
 * does not construct the service until its first use. Thus, Greenlight does not
 * dispose an unused service. A factory for a type that is not final can return
 * a subclass. A proxy of the registered type cannot forward method calls to a
 * subclass, so Greenlight constructs these services when it resolves them.
 *
 * @internal
 */
final class ScopeContainer
{
    /**
     * @var array<string, array<class-string, object>>
     */
    private array $services = [];

    /** @var list<object> */
    private array $initializedServices = [];

    /**
     * @throws UnresolvableService
     */
    public function get(ServiceDefinition $definition): object
    {
        $source = $definition->source ?? '';
        $existing = $this->services[$source][$definition->type] ?? null;

        if ($existing !== null) {
            return $existing;
        }

        $service = $this->instantiate($definition);
        $this->services[$source][$definition->type] = $service;

        return $service;
    }

    /**
     * Disposes constructed services in reverse creation order. It records each
     * failure and continues to dispose the remaining services.
     *
     * @return list<\Throwable>
     */
    public function dispose(): array
    {
        $failures = [];

        while (($service = \array_pop($this->initializedServices)) !== null) {
            $reflection = new \ReflectionClass($service);

            if ($reflection->isUninitializedLazyObject($service)) {
                continue;
            }

            if (!$service instanceof Disposable) {
                continue;
            }

            try {
                $service->dispose();
            } catch (\Throwable $failure) {
                $failures[] = $failure;
            }
        }

        $this->services = [];

        return $failures;
    }

    /**
     * @throws UnresolvableService
     */
    private function instantiate(ServiceDefinition $definition): object
    {
        $reflection = new \ReflectionClass($definition->type);
        $initializedServices = &$this->initializedServices;
        $factory = static function () use ($definition, &$initializedServices): object {
            $service = ($definition->factory)();

            if (!$service instanceof $definition->type) {
                throw UnresolvableService::factoryTypeMismatch(
                    $definition->type,
                    $service,
                );
            }

            $initializedServices[] = $service;

            return $service;
        };

        // A final type guarantees that the proxy and the factory result have
        // the same class. The proxy then accepts each instance that the
        // factory records for disposal.
        if (!$reflection->isFinal()) {
            return $factory();
        }

        try {
            $proxy = $reflection->newLazyProxy($factory);
        } catch (\ReflectionException|\Error) {
            // Greenlight constructs a class immediately if PHP cannot create a
            // lazy proxy for it. The factory has not run, so this catch does
            // not hide a factory error.
            return $factory();
        }

        // PHP does not make stateless classes lazy and does not call their initializer.
        return $reflection->isUninitializedLazyObject($proxy) ? $proxy : $factory();
    }
}
