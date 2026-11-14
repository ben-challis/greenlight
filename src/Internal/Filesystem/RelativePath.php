<?php

declare(strict_types=1);

namespace Greenlight\Internal\Filesystem;

/**
 * Checks relative paths that must stay below a base directory.
 *
 * isRelative() rejects an empty path, a leading `/`, and a backslash.
 * hasPlainSegments() rejects empty, `.`, and `..` segments. isSafe() applies
 * both checks.
 *
 * @internal
 */
final class RelativePath
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /** @phpstan-assert-if-true non-empty-string $path */
    public static function isSafe(string $path): bool
    {
        return self::isRelative($path) && self::hasPlainSegments($path);
    }

    /** @phpstan-assert-if-true non-empty-string $path */
    public static function isRelative(string $path): bool
    {
        return $path !== '' && !\str_starts_with($path, '/') && !\str_contains($path, '\\');
    }

    public static function hasPlainSegments(string $path): bool
    {
        return \array_all(
            \explode('/', $path),
            static fn(string $segment): bool => !\in_array($segment, ['', '.', '..'], true),
        );
    }
}
