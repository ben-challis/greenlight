<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Services created within one scope instance, disposed in reverse creation
 * order when the scope closes. Services are lazy proxies where the class
 * allows it, so an injected but untouched service is never constructed and
 * never disposed.
 *
 * @internal
 */
final class ScopeContainer
{
    /**
     * @var array<class-string, object>
     */
    private array $services = [];

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
     * Disposes created services in reverse creation order. Never throws;
     * every failure is collected so one broken teardown cannot leak the rest.
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

    private function instantiate(ServiceDefinition $definition): object
    {
        $reflection = new \ReflectionClass($definition->type);

        try {
            return $reflection->newLazyProxy(static fn(): object => ($definition->factory)());
        } catch (\ReflectionException|\Error) {
            // Classes that cannot be lazy (internal classes among them) are
            // constructed eagerly. The factory has not run at this point, so
            // nothing real can be swallowed here.
            return ($definition->factory)();
        }
    }
}
