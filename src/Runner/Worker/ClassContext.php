<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Discovery\DataSetExpander;

/**
 * Per-class execution state: the reflection, hook lists, and the class's
 * expanded data sets (providers run once per class, cached for the class
 * scope's lifetime).
 *
 * @internal
 */
final class ClassContext
{
    /**
     * @var array<string, non-empty-array<string, mixed>>
     */
    private array $dataSets = [];

    /**
     * @param \ReflectionClass<object> $reflection
     * @param list<\ReflectionMethod> $beforeHooks declaration order
     * @param list<\ReflectionMethod> $afterHooks reverse declaration order
     */
    private function __construct(
        public readonly \ReflectionClass $reflection,
        public readonly array $beforeHooks,
        public readonly array $afterHooks,
        private readonly float $providerBudgetSeconds,
    ) {}

    /**
     * @param non-empty-string $class
     */
    public static function for(string $class, float $providerBudgetSeconds = 5.0): self
    {
        if (!\class_exists($class)) {
            throw new \RuntimeException(\sprintf(
                'Test class %s from the plan is not loadable in this process.',
                $class,
            ));
        }

        $reflection = new \ReflectionClass($class);
        $before = [];
        $after = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isAbstract()) {
                continue;
            }

            if ($method->getAttributes(Before::class) !== []) {
                $before[] = $method;
            }

            if ($method->getAttributes(After::class) !== []) {
                $after[] = $method;
            }
        }

        return new self($reflection, $before, \array_reverse($after), $providerBudgetSeconds);
    }

    /**
     * Positional arguments for one data-set key. The key came from the plan;
     * a key the provider no longer yields means the code changed between
     * planning and execution, and that is an error.
     *
     * @param non-empty-string $provider
     * @param non-empty-string $testMethod
     *
     * @return list<mixed>
     */
    public function argumentsFor(string $provider, string $testMethod, string $key): array
    {
        if (!\array_key_exists($provider, $this->dataSets)) {
            $this->dataSets[$provider] = new DataSetExpander()->expand(
                $this->reflection,
                $testMethod,
                $provider,
                $this->providerBudgetSeconds,
            );
        }

        $sets = $this->dataSets[$provider];

        if (!\array_key_exists($key, $sets)) {
            throw new \RuntimeException(\sprintf(
                "Data set '%s' of provider %s::%s() is in the plan but the provider no longer yields it. "
                . 'Re-run discovery.',
                $key,
                $this->reflection->getName(),
                $provider,
            ));
        }

        $value = $sets[$key];

        if (!\is_array($value)) {
            throw new \RuntimeException(\sprintf(
                "Data set '%s' of provider %s::%s() must be an array of arguments, got %s.",
                $key,
                $this->reflection->getName(),
                $provider,
                \get_debug_type($value),
            ));
        }

        return \array_values($value);
    }
}
