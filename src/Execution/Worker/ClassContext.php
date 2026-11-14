<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Test\DataSet\DataSetError;
use Greenlight\Test\DataSet\DataSetExpander;

/**
 * Contains the execution state for one test class. This state includes
 * reflection, hook lists, and expanded data sets.
 *
 * Data providers run one time for each class assignment. The class context
 * stores their expanded data sets.
 *
 * @internal
 */
final class ClassContext
{
    /**
     * Resolved data sets for each test method.
     *
     * @var array<string, array<array-key, mixed>>
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
     *
     * @throws WorkerError
     */
    public static function for(string $class, float $providerBudgetSeconds = 5.0): self
    {
        if (!\class_exists($class)) {
            throw WorkerError::classUnavailable($class);
        }

        $reflection = new \ReflectionClass($class);
        $before = [];
        $after = [];

        foreach (self::hookMethods($reflection) as $method) {
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
     * Returns the public instance methods of the class from the root ancestor
     * to the class itself. Each class contributes its own methods in
     * declaration order, then the methods of its traits. An overriding method
     * takes the place of the class that declares the override.
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<\ReflectionMethod>
     */
    private static function hookMethods(\ReflectionClass $reflection): array
    {
        $hierarchy = [$reflection->getName()];
        $class = $reflection;

        while (($class = $class->getParentClass()) !== false) {
            \array_unshift($hierarchy, $class->getName());
        }

        $byDeclaringClass = \array_fill_keys($hierarchy, []);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isAbstract()) {
                continue;
            }

            $byDeclaringClass[$method->getDeclaringClass()->getName()][] = $method;
        }

        $methods = [];

        foreach ($byDeclaringClass as $declared) {
            $methods = [...$methods, ...$declared];
        }

        return $methods;
    }

    /**
     * Returns positional arguments for one data-set key.
     *
     * The method gets them from #[DataRow] attributes and the #[DataSet] data
     * provider.
     *
     * The execution plan supplies the key. If the current data sets do not
     * contain it, Greenlight reports an error. The code or provider output
     * can differ from discovery.
     *
     * @param non-empty-string|null $provider
     * @param non-empty-string|null $providerClass
     * @param non-empty-string $testMethod
     *
     * @return list<mixed>
     *
     * @throws DataSetError
     * @throws WorkerError
     */
    public function argumentsFor(?string $provider, ?string $providerClass, string $testMethod, string $key): array
    {
        if (!\array_key_exists($testMethod, $this->dataSets)) {
            $this->dataSets[$testMethod] = new DataSetExpander()->rowsFor(
                $this->reflection,
                $testMethod,
                $provider,
                $this->providerBudgetSeconds,
                $providerClass,
            );
        }

        $sets = $this->dataSets[$testMethod];

        if (!\array_key_exists($key, $sets)) {
            throw WorkerError::dataSetMissing(
                $key,
                $this->reflection->getName(),
                $testMethod,
            );
        }

        $value = $sets[$key];

        if (!\is_array($value)) {
            throw WorkerError::invalidDataSet(
                $key,
                $this->reflection->getName(),
                $testMethod,
                $value,
            );
        }

        return \array_values($value);
    }
}
