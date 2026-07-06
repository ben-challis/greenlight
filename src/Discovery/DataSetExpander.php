<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

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
     * @param \ReflectionClass<object> $class
     * @param non-empty-string $testMethod
     * @param non-empty-string $provider
     *
     * @return non-empty-list<string> derived keys in provider order
     */
    public function keysFor(\ReflectionClass $class, string $testMethod, string $provider, float $budgetSeconds): array
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

        $keys = [];
        $seen = [];

        try {
            foreach ($result as $key => $value) {
                if (\hrtime(true) > $deadline) {
                    throw DiscoveryError::providerTooSlow($className, $provider, $budgetSeconds);
                }

                $derived = $this->deriveKey($className, $provider, $key);

                if (isset($seen[$derived])) {
                    throw DiscoveryError::duplicateDataSetKey($className, $testMethod, $derived);
                }

                $seen[$derived] = true;
                $keys[] = $derived;
            }
        } catch (DiscoveryError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw DiscoveryError::providerThrew($className, $provider, $e);
        }

        if (\hrtime(true) > $deadline) {
            throw DiscoveryError::providerTooSlow($className, $provider, $budgetSeconds);
        }

        if ($keys === []) {
            throw DiscoveryError::providerYieldedNothing($className, $provider);
        }

        return $keys;
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
