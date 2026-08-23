<?php

declare(strict_types=1);

namespace Greenlight\Test\DataSet;

/**
 * Data-set expansion raises this error for invalid providers, rows, and keys.
 *
 * @internal
 */
final class DataSetError extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function invalidAttribute(string $where, \Throwable $cause): self
    {
        return new self(
            \sprintf('Attribute on %s is invalid: %s', $where, $cause->getMessage()),
            $cause,
        );
    }

    public static function providerClassMissing(string $class, string $method, string $providerClass): self
    {
        return new self(\sprintf(
            'Test method %s::%s() references missing data-set provider class "%s".',
            $class,
            $method,
            $providerClass,
        ));
    }

    public static function providerMissing(string $class, string $method, string $providerClass, string $provider): self
    {
        return new self(\sprintf(
            'Test method %s::%s() references data-set provider %s::%s(), but the provider does not exist.',
            $class,
            $method,
            $providerClass,
            $provider,
        ));
    }

    public static function providerNotPublicStatic(
        string $class,
        string $method,
        string $providerClass,
        string $provider,
    ): self {
        return new self(\sprintf(
            'Test method %s::%s() references data-set provider %s::%s(). Declare the provider as public and static.',
            $class,
            $method,
            $providerClass,
            $provider,
        ));
    }

    public static function providerNotIterable(string $class, string $provider, string $actualType): self
    {
        return new self(\sprintf(
            'Data-set provider %s::%s() returned %s. Return an iterable from the provider.',
            $class,
            $provider,
            $actualType,
        ));
    }

    public static function providerThrew(string $class, string $provider, \Throwable $cause): self
    {
        return new self(
            \sprintf(
                'Data-set provider %s::%s() threw %s: %s',
                $class,
                $provider,
                $cause::class,
                $cause->getMessage(),
            ),
            $cause,
        );
    }

    public static function providerTooSlow(string $class, string $provider, float $budgetSeconds): self
    {
        return new self(\sprintf(
            'Data-set provider %s::%s() exceeded the %.3f-second discovery time budget. Providers run during plan creation. Keep them pure and fast.',
            $class,
            $provider,
            $budgetSeconds,
        ));
    }

    public static function providerYieldedNothing(string $class, string $provider): self
    {
        return new self(\sprintf(
            'Data-set provider %s::%s() produced no data sets. Produce at least one data set.',
            $class,
            $provider,
        ));
    }

    public static function providerKeyInvalid(string $class, string $provider, string $keyType): self
    {
        return new self(\sprintf(
            'Data-set provider %s::%s() produced a key of type %s. Use string or integer keys.',
            $class,
            $provider,
            $keyType,
        ));
    }

    public static function duplicateDataSetKey(string $class, string $method, string $key): self
    {
        return new self(\sprintf(
            'Data sets for %s::%s() contain key "%s" more than once. Use each key only once for the test method.',
            $class,
            $method,
            $key,
        ));
    }
}
