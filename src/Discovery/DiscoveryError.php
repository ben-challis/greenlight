<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Test\DataSet\DataSetError;

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

    public static function invalidDataSet(DataSetError $cause): self
    {
        return new self($cause->getMessage(), $cause);
    }

    /** @param non-empty-list<non-empty-string> $ids */
    public static function exactTestsNotFound(array $ids): self
    {
        return new self(\sprintf(
            'Greenlight did not find the requested exact test %s: %s.',
            \count($ids) === 1 ? 'ID' : 'IDs',
            \implode(', ', $ids),
        ));
    }
}
