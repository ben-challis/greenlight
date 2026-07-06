<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * The active scope containers of one worker. The run and suite containers
 * live for the worker's lifetime; the class and test containers are opened
 * and closed by the worker as execution progresses. The suite scope currently
 * shares the run container's lifetime because suite boundaries are not yet
 * part of the execution plan.
 *
 * @internal
 */
final class HarnessScopes
{
    private readonly ScopeContainer $run;

    private readonly ScopeContainer $suite;

    private ?ScopeContainer $class = null;

    private ?ScopeContainer $test = null;

    public function __construct(
        private readonly HarnessRegistry $registry,
    ) {
        $this->run = new ScopeContainer();
        $this->suite = new ScopeContainer();
    }

    /**
     * @param class-string $type
     * @param non-empty-string $consumer
     */
    public function resolve(string $type, string $consumer): object
    {
        $definition = $this->registry->find($type);

        if (!$definition instanceof ServiceDefinition) {
            throw UnresolvableService::unknownType($type, $consumer);
        }

        return $this->containerFor($definition->scope)->get($definition);
    }

    public function openClass(): void
    {
        $this->class = new ScopeContainer();
    }

    /**
     * @return list<\Throwable>
     */
    public function closeClass(): array
    {
        $failures = $this->class?->dispose() ?? [];
        $this->class = null;

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
