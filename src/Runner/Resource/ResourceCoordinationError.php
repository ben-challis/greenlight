<?php

declare(strict_types=1);

namespace Greenlight\Runner\Resource;

/**
 * Reports a machine resource coordination failure.
 *
 * @internal
 */
final class ResourceCoordinationError extends \RuntimeException
{
    public static function cannotCreateDirectory(string $path, ?string $warning): self
    {
        return new self(\sprintf(
            'Machine resource coordination could not create directory "%s"%s.',
            $path,
            self::warning($warning),
        ));
    }

    public static function cannotOpen(string $path, ?string $warning): self
    {
        return new self(\sprintf(
            'Machine resource coordination could not open lock file "%s"%s.',
            $path,
            self::warning($warning),
        ));
    }

    public static function cannotLock(string $path, ?string $warning): self
    {
        return new self(\sprintf(
            'Machine resource coordination could not lock file "%s"%s.',
            $path,
            self::warning($warning),
        ));
    }

    public static function cannotWriteDefinition(string $path, ?string $warning): self
    {
        return new self(\sprintf(
            'Machine resource coordination could not write definition file "%s"%s.',
            $path,
            self::warning($warning),
        ));
    }

    public static function invalidDefinition(string $resource, string $namespace): self
    {
        return new self(\sprintf(
            'Machine resource "%s" has an invalid active definition in coordination namespace "%s". Stop all Greenlight runs that use this namespace. Then try again.',
            $resource,
            $namespace,
        ));
    }

    public static function conflictingLimit(string $resource, string $namespace, int $active, int $configured): self
    {
        return new self(\sprintf(
            'Machine resource "%s" has active limit %d in coordination namespace "%s". This run configured limit %d. Use the same limit for concurrent Greenlight runs.',
            $resource,
            $active,
            $namespace,
            $configured,
        ));
    }

    public static function definitionBusy(string $resource, string $namespace): self
    {
        return new self(\sprintf(
            'Machine resource "%s" could not get the definition lock in coordination namespace "%s".',
            $resource,
            $namespace,
        ));
    }

    public static function nestedAcquisition(string $resource, string $namespace): self
    {
        return new self(\sprintf(
            'An outer Greenlight run holds machine resource "%s" in coordination namespace "%s". A nested run cannot get the same resource.',
            $resource,
            $namespace,
        ));
    }

    private static function warning(?string $warning): string
    {
        return $warning === null || $warning === '' ? '' : ': ' . \rtrim($warning, '.');
    }
}
