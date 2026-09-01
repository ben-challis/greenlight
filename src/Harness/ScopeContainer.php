<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Stores services for one service scope.
 *
 * dispose() disposes the services in reverse creation order.
 *
 * If the service class supports a lazy proxy, Greenlight does not construct
 * the service until its first use. Thus, Greenlight does not dispose an
 * unused service.
 *
 * @internal
 */
final class ScopeContainer
{
    /**
     * @var array<class-string, object>
     */
    private array $services = [];

    /**
     * @throws UnresolvableService
     */
    public function get(ServiceDefinition $definition): object
    {
        $existing = $this->services[$definition->type] ?? null;

        if ($existing !== null) {
            return $existing;
        }

        $service = $this->instantiate($definition);
        $this->services[$definition->type] = $service;

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

        foreach (\array_reverse($this->services) as $service) {
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
        $factory = static function () use ($definition): object {
            $service = ($definition->factory)();

            if (!$service instanceof $definition->type) {
                throw UnresolvableService::factoryTypeMismatch(
                    $definition->type,
                    $service,
                );
            }

            return $service;
        };

        try {
            return $reflection->newLazyProxy($factory);
        } catch (\ReflectionException|\Error) {
            // Greenlight constructs a class immediately if PHP cannot create a
            // lazy proxy for it. The factory has not run, so this catch does
            // not hide a factory error.
            return $factory();
        }
    }
}
