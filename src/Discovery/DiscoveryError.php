<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

/**
 * Discovery raises this error when it cannot make plan entries from a test file.
 *
 * Discovery reports each file that it cannot resolve. Each failure type has a
 * named constructor. Its message identifies the applicable file, class, or
 * method.
 *
 * @internal
 */
final class DiscoveryError extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function directoryNotFound(string $directory, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf('Discovery directory "%s" is missing or is not a directory.', $directory),
            $previous,
        );
    }

    public static function unreadableFile(
        string $file,
        ?string $reason = null,
        ?\Throwable $previous = null,
    ): self {
        return new self(\sprintf(
            'Greenlight cannot read test file "%s"%s.',
            $file,
            $reason === null ? '' : ': ' . $reason,
        ), $previous);
    }

    public static function noClassInFile(string $file): self
    {
        return new self(\sprintf('Test file "%s" does not declare a class, interface, trait, or enum.', $file));
    }

    public static function classNameMismatch(string $file, string $declared, string $expected): self
    {
        return new self(\sprintf(
            'Test file "%s" declares "%s". Its file name requires "%s". Rename the class or file so the names match.',
            $file,
            $declared,
            $expected,
        ));
    }

    public static function classNotAutoloadable(string $file, string $class): self
    {
        return new self(\sprintf(
            'The autoloader cannot load class "%s" from "%s". Check that the namespace matches the PSR-4 mapping for this path.',
            $class,
            $file,
        ));
    }

    public static function classLoadedFromOtherFile(string $file, string $class, string $actualFile): self
    {
        return new self(\sprintf(
            'The autoloader loaded class "%s" from "%s". It expected the class in "%s". Only one file can declare a class.',
            $class,
            $actualFile,
            $file,
        ));
    }

    public static function testMethodNotRunnable(string $class, string $method, string $why): self
    {
        return new self(\sprintf('Greenlight cannot run test method %s::%s() because %s.', $class, $method, $why));
    }

    public static function invalidAttribute(string $where, \Throwable $cause): self
    {
        return new self(
            \sprintf('Attribute on %s is invalid: %s', $where, $cause->getMessage()),
            $cause,
        );
    }

    public static function incompatibleAttributes(string $class, string $first, string $second): self
    {
        return new self(\sprintf(
            'Test class "%s" uses incompatible attributes #[%s] and #[%s]. Remove one of these attributes.',
            $class,
            $first,
            $second,
        ));
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
