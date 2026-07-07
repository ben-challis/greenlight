<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Attribute\DataRow;

/**
 * Invokes a #[DataSet] provider at plan time and derives one stable string
 * key per yielded data set. Providers are the only code discovery executes;
 * they must be pure and are held to a per-provider time budget.
 *
 * Key derivation: printable string keys are used as-is, integer keys become
 * "#<value>", and empty or non-printable string keys become the first eight
 * hex characters of the key's SHA-256 hash.
 *
 * @internal
 */
final class DataSetExpander
{
    /**
     * @param \ReflectionClass<covariant object> $class
     * @param non-empty-string $testMethod
     * @param non-empty-string $provider
     *
     * @return non-empty-list<string> derived keys in provider order
     *
     * @throws DiscoveryError
     */
    public function keysFor(\ReflectionClass $class, string $testMethod, string $provider, float $budgetSeconds): array
    {
        return \array_keys($this->expand($class, $testMethod, $provider, $budgetSeconds));
    }

    /**
     * Every data set of a test method under one key space: inline #[DataRow]
     * attributes in declaration order (labelled, or "#<position>" by their
     * position among the rows), then the #[DataSet] provider's yields. Both
     * the planner and the worker resolve through this method, so keys can
     * never drift between plan and execution. An empty result means the test
     * takes no data sets.
     *
     * @param \ReflectionClass<covariant object> $class
     * @param non-empty-string $testMethod
     * @param non-empty-string|null $provider
     *
     * @return array<string, mixed>
     *
     * @throws DiscoveryError
     */
    public function rowsFor(\ReflectionClass $class, string $testMethod, ?string $provider, float $budgetSeconds): array
    {
        $className = $class->getName();
        $rows = [];
        $position = 0;

        foreach ($class->getMethod($testMethod)->getAttributes(DataRow::class) as $attribute) {
            $row = $attribute->newInstance();
            $key = $row->label ?? \sprintf('#%d', $position);

            if (\array_key_exists($key, $rows)) {
                throw DiscoveryError::duplicateDataSetKey($className, $testMethod, $key);
            }

            $rows[$key] = $row->arguments;
            ++$position;
        }

        if ($provider === null) {
            return $rows;
        }

        foreach ($this->expand($class, $testMethod, $provider, $budgetSeconds) as $key => $value) {
            if (\array_key_exists($key, $rows)) {
                throw DiscoveryError::duplicateDataSetKey($className, $testMethod, $key);
            }

            $rows[$key] = $value;
        }

        return $rows;
    }

    /**
     * Derived key mapped to the yielded data set, in provider order. Both the
     * planner and the worker resolve data sets through this method, so the
     * derivation can never drift between plan and execution.
     *
     * @param \ReflectionClass<covariant object> $class
     * @param non-empty-string $testMethod
     * @param non-empty-string $provider
     *
     * @return non-empty-array<string, mixed>
     *
     * @throws DiscoveryError
     */
    public function expand(\ReflectionClass $class, string $testMethod, string $provider, float $budgetSeconds): array
    {
        $className = $class->getName();

        if (!$class->hasMethod($provider)) {
            throw DiscoveryError::providerMissing($className, $testMethod, $provider);
        }

        $method = $class->getMethod($provider);

        if (!$method->isPublic() || !$method->isStatic()) {
            throw DiscoveryError::providerNotPublicStatic($className, $testMethod, $provider);
        }

        $deadline = \hrtime(true) + (int) \round($budgetSeconds * 1_000_000_000);

        try {
            $result = $method->invoke(null);
        } catch (\Throwable $e) {
            throw DiscoveryError::providerThrew($className, $provider, $e);
        }

        if (!\is_iterable($result)) {
            throw DiscoveryError::providerNotIterable($className, $provider, \get_debug_type($result));
        }

        $dataSets = [];

        try {
            foreach ($result as $key => $value) {
                if (\hrtime(true) > $deadline) {
                    throw DiscoveryError::providerTooSlow($className, $provider, $budgetSeconds);
                }

                $derived = $this->deriveKey($className, $provider, $key);

                if (\array_key_exists($derived, $dataSets)) {
                    throw DiscoveryError::duplicateDataSetKey($className, $testMethod, $derived);
                }

                $dataSets[$derived] = $value;
            }
        } catch (DiscoveryError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw DiscoveryError::providerThrew($className, $provider, $e);
        }

        if (\hrtime(true) > $deadline) {
            throw DiscoveryError::providerTooSlow($className, $provider, $budgetSeconds);
        }

        if ($dataSets === []) {
            throw DiscoveryError::providerYieldedNothing($className, $provider);
        }

        return $dataSets;
    }

    private function deriveKey(string $class, string $provider, mixed $key): string
    {
        if (\is_int($key)) {
            return \sprintf('#%d', $key);
        }

        if (!\is_string($key)) {
            throw DiscoveryError::providerKeyInvalid($class, $provider, \get_debug_type($key));
        }

        if ($key !== '' && \preg_match('/^\P{C}+$/u', $key) === 1) {
            return $key;
        }

        return \substr(\hash('sha256', $key), 0, 8);
    }
}
