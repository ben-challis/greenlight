<?php

declare(strict_types=1);

namespace Greenlight\Sandbox;

/**
 * A temporary-directory operation cannot create, validate, or remove a path.
 */
final class TemporaryDirectoryError extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function rootCreationFailed(string $path, ?string $warning): self
    {
        return new self(\sprintf(
            'Failed to create temp directory "%s"%s.',
            $path,
            $warning === null ? '' : ': ' . $warning,
        ));
    }

    public static function subdirectoryCreationFailed(string $path, ?string $warning): self
    {
        return new self(\sprintf(
            'Failed to create subdirectory "%s"%s.',
            $path,
            $warning === null ? '' : ': ' . $warning,
        ));
    }

    public static function symbolicLink(string $path): self
    {
        return new self(\sprintf('Subdirectory path "%s" contains a symbolic link.', $path));
    }

    public static function rootLinkRemovalFailed(string $path, ?string $warning): self
    {
        return new self(\sprintf(
            'Failed to remove temp directory symbolic link "%s"%s.',
            $path,
            $warning === null ? '' : ': ' . $warning,
        ));
    }

    public static function entryRemovalFailed(string $entry, string $root): self
    {
        return new self(\sprintf('Failed to remove "%s" while disposing temp directory "%s".', $entry, $root));
    }

    public static function rootRemovalFailed(string $path, ?string $warning): self
    {
        return new self(\sprintf(
            'Failed to remove temp directory "%s"%s.',
            $path,
            $warning === null ? '' : ': ' . $warning,
        ));
    }
}
