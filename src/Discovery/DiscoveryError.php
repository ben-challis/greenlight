<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

/**
 * Raised whenever discovery cannot turn a test file into plan entries.
 *
 * Discovery never silently skips a file it cannot resolve; every failure
 * mode has a named constructor whose message identifies the file, class, or
 * method involved.
 *
 * @internal
 */
final class DiscoveryError extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function directoryNotFound(string $directory): self
    {
        return new self(\sprintf('Discovery directory "%s" does not exist or is not a directory.', $directory));
    }

    public static function unreadableFile(string $file): self
    {
        return new self(\sprintf('Test file "%s" could not be read.', $file));
    }

    public static function noClassInFile(string $file): self
    {
        return new self(\sprintf('Test file "%s" does not declare any class, interface, trait, or enum.', $file));
    }

    public static function classNameMismatch(string $file, string $declared, string $expected): self
    {
        return new self(\sprintf(
            'Test file "%s" declares "%s" but its file name requires a declaration named "%s". Rename the class or the file so they agree.',
            $file,
            $declared,
            $expected,
        ));
    }

    public static function classNotAutoloadable(string $file, string $class): self
    {
        return new self(\sprintf(
            'Class "%s" declared in "%s" is not autoloadable. The namespace probably does not match the PSR-4 mapping for that path.',
            $class,
            $file,
        ));
    }

    public static function classLoadedFromOtherFile(string $file, string $class, string $actualFile): self
    {
        return new self(\sprintf(
            'Class "%s" declared in "%s" was autoloaded from "%s" instead. Two files must not declare the same class.',
            $class,
            $file,
            $actualFile,
        ));
    }

    public static function testMethodNotRunnable(string $class, string $method, string $why): self
    {
        return new self(\sprintf('Test method %s::%s() cannot run: %s.', $class, $method, $why));
    }

    public static function invalidAttribute(string $where, \Throwable $cause): self
    {
        return new self(
            \sprintf('Invalid attribute on %s: %s', $where, $cause->getMessage()),
            $cause,
        );
    }

    public static function providerMissing(string $class, string $method, string $provider): self
    {
        return new self(\sprintf(
            'Data-set provider "%s" referenced by %s::%s() does not exist on %s.',
            $provider,
            $class,
            $method,
            $class,
        ));
    }

    public static function providerNotPublicStatic(string $class, string $method, string $provider): self
    {
        return new self(\sprintf(
            'Data-set provider %s::%s() referenced by %s::%s() must be public and static.',
            $class,
            $provider,
            $class,
            $method,
        ));
    }

    public static function providerNotIterable(string $class, string $provider, string $actualType): self
    {
        return new self(\sprintf(
            'Data-set provider %s::%s() must return an iterable, got %s.',
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
            'Data-set provider %s::%s() exceeded the discovery time budget of %.3f seconds. Providers run at plan time and must be pure and fast.',
            $class,
            $provider,
            $budgetSeconds,
        ));
    }

    public static function providerYieldedNothing(string $class, string $provider): self
    {
        return new self(\sprintf(
            'Data-set provider %s::%s() yielded no data sets. A provider must produce at least one.',
            $class,
            $provider,
        ));
    }

    public static function providerKeyInvalid(string $class, string $provider, string $keyType): self
    {
        return new self(\sprintf(
            'Data-set provider %s::%s() yielded a key of type %s. Keys must be strings or integers.',
            $class,
            $provider,
            $keyType,
        ));
    }

    public static function duplicateDataSetKey(string $class, string $method, string $key): self
    {
        return new self(\sprintf(
            'Data sets for %s::%s() produced the key "%s" more than once. Keys must be unique per test method.',
            $class,
            $method,
            $key,
        ));
    }
}
