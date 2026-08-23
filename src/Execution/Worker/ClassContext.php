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
     * Returns positional arguments for one data-set key.
     *
     * The method gets them from #[DataRow] attributes and the #[DataSet] data
     * provider.
 *
     * The execution plan supplies the key. If the method data sets do not
     * contain it, the code changed after discovery. This condition is an
     * error.
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
                \get_debug_type($value),
            );
        }

        return \array_values($value);
    }
}
