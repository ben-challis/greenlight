<?php

declare(strict_types=1);

namespace Greenlight\Test\DataSet;

use Greenlight\Attribute\DataRow;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Invokes a #[DataSet] provider when Greenlight makes the execution plan.
 * It makes one stable string key for each data set.
 *
 * Discovery executes only data providers. Providers MUST be pure. For the
 * same inputs, a provider MUST supply the same data. A provider MUST NOT
 * change external state. Greenlight checks the time budget between rows and
 * after iteration. The budget cannot interrupt a blocked provider.
 *
 * Greenlight does not change a printable string key. It converts an integer
 * key to "#<value>". For an empty or nonprintable string key, it uses the
 * first eight hexadecimal characters of the SHA-256 hash.
 *
 * @internal
 */
final readonly class DataSetExpander
{
    /**
     * @var \Closure(): (int|float)
     */
    private \Closure $monotonicTime;

    /**
     * @param (callable(): (int|float))|null $monotonicTime
     */
    public function __construct(?callable $monotonicTime = null)
    {
        $this->monotonicTime = $monotonicTime === null
            ? static fn(): int|float => \hrtime(true)
            : $monotonicTime(...);
    }

    /**
     * Returns each data set of a test method in one key space.
     *
     * Inline #[DataRow] attributes occur first in declaration order. Their
     * labels identify them. An attribute without a label uses "#<position>".
     * Provider data sets occur after the attributes. An empty result means
     * that the test has no data sets.
     *
     * The planner and worker both use this method. Thus, plan keys and
     * execution keys remain equal.
     *
     * @param \ReflectionClass<covariant object> $class
     * @param non-empty-string $testMethod
     * @param non-empty-string|null $provider
     * @param non-empty-string|null $providerClass
     *
     * @return array<array-key, mixed>
     *
     * @throws DataSetError
     */
    public function rowsFor(
        \ReflectionClass $class,
        string $testMethod,
        ?string $provider,
        float $budgetSeconds,
        ?string $providerClass = null,
    ): array {
        $className = $class->getName();
        $rows = [];
        $position = 0;

        foreach ($class->getMethod($testMethod)->getAttributes(DataRow::class) as $attribute) {
            $row = ErrorTrap::run(
                static fn() => $attribute->newInstance(),
                wrap: static fn(\Throwable $error): DataSetError => DataSetError::invalidAttribute(
                    $className . '::' . $testMethod . '()',
                    $error,
                ),
            );

            $key = $row->label === null
                ? \sprintf('#%d', $position)
                : $this->deriveKey($className, $testMethod, $row->label);

            if (\array_key_exists($key, $rows)) {
                throw DataSetError::duplicateDataSetKey($className, $testMethod, $key);
            }

            $rows[$key] = $row->arguments;
            ++$position;
        }

        if ($provider === null) {
            return $rows;
        }

        $providerReflection = $class;

        if ($providerClass !== null) {
            if (!\class_exists($providerClass)) {
                throw DataSetError::providerClassMissing($className, $testMethod, $providerClass);
            }

            $providerReflection = new \ReflectionClass($providerClass);
        }

        foreach ($this->expand($class, $providerReflection, $testMethod, $provider, $budgetSeconds) as $key => $value) {
            if (\array_key_exists($key, $rows)) {
                throw DataSetError::duplicateDataSetKey($className, $testMethod, (string) $key);
            }

            $rows[$key] = $value;
        }

        return $rows;
    }

    /**
     * Maps each key to its data set in provider order.
     *
     * @param \ReflectionClass<covariant object> $testClass
     * @param \ReflectionClass<covariant object> $providerClass
     * @param non-empty-string $testMethod
     * @param non-empty-string $provider
     *
     * @return non-empty-array<array-key, mixed>
     *
     * @throws DataSetError
     */
    private function expand(
        \ReflectionClass $testClass,
        \ReflectionClass $providerClass,
        string $testMethod,
        string $provider,
        float $budgetSeconds,
    ): array {
        $testClassName = $testClass->getName();
        $providerClassName = $providerClass->getName();

        if (!$providerClass->hasMethod($provider)) {
            throw DataSetError::providerMissing($testClassName, $testMethod, $providerClassName, $provider);
        }

        $method = $providerClass->getMethod($provider);

        if (!$method->isPublic() || !$method->isStatic()) {
            throw DataSetError::providerNotPublicStatic($testClassName, $testMethod, $providerClassName, $provider);
        }

        $startedAt = ($this->monotonicTime)();
        $budgetNanoseconds = $budgetSeconds * 1_000_000_000;

        try {
            $result = $method->invoke(null);
        } catch (\Throwable $e) {
            throw DataSetError::providerThrew($providerClassName, $provider, $e);
        }

        if (!\is_iterable($result)) {
            throw DataSetError::providerNotIterable($providerClassName, $provider, $result);
        }

        $dataSets = [];

        foreach ($this->providerRows($result, $providerClassName, $provider) as $key => $value) {
            if (($this->monotonicTime)() - $startedAt > $budgetNanoseconds) {
                throw DataSetError::providerTooSlow($providerClassName, $provider, $budgetSeconds);
            }

            $derived = $this->deriveKey($providerClassName, $provider, $key);

            if (\array_key_exists($derived, $dataSets)) {
                throw DataSetError::duplicateDataSetKey($testClassName, $testMethod, $derived);
            }

            $dataSets[$derived] = $value;
        }

        if (($this->monotonicTime)() - $startedAt > $budgetNanoseconds) {
            throw DataSetError::providerTooSlow($providerClassName, $provider, $budgetSeconds);
        }

        if ($dataSets === []) {
            throw DataSetError::providerYieldedNothing($providerClassName, $provider);
        }

        return $dataSets;
    }

    /**
     * Keeps provider iteration failures separate from expander validation failures.
     *
     * @param iterable<mixed, mixed> $rows
     *
     * @return iterable<mixed, mixed>
     *
     * @throws DataSetError
     */
    private function providerRows(iterable $rows, string $providerClass, string $provider): iterable
    {
        try {
            yield from $rows;
        } catch (\Throwable $e) {
            throw DataSetError::providerThrew($providerClass, $provider, $e);
        }
    }

    /**
     * @throws DataSetError
     */
    private function deriveKey(string $class, string $provider, mixed $key): string
    {
        if (\is_int($key)) {
            return \sprintf('#%d', $key);
        }

        if (!\is_string($key)) {
            throw DataSetError::providerKeyInvalid($class, $provider, $key);
        }

        if ($key !== '' && \preg_match('/^\P{C}+\z/u', $key) === 1) {
            return $key;
        }

        return \substr(\hash('sha256', $key), 0, 8);
    }
}
