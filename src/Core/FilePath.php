<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * Identifies absolute POSIX and Windows file-system paths.
 *
 * @internal
 */
final class FilePath
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function resolve(
        string $path,
        string $workingDirectory,
        string $directorySeparator = \DIRECTORY_SEPARATOR,
    ): string {
        if (self::isAbsolute($path, $directorySeparator)) {
            return $path;
        }

        return \rtrim($workingDirectory, '/\\') . $directorySeparator . $path;
    }

    public static function isAbsolute(
        string $path,
        string $directorySeparator = \DIRECTORY_SEPARATOR,
    ): bool {
        if ($directorySeparator !== '\\') {
            return \str_starts_with($path, '/');
        }

        if (\str_starts_with($path, '/') || \str_starts_with($path, '\\')) {
            return true;
        }

        if (\strlen($path) < 3 || $path[1] !== ':' || $path[2] !== '/' && $path[2] !== '\\') {
            return false;
        }

        return $path[0] >= 'A' && $path[0] <= 'Z'
            || $path[0] >= 'a' && $path[0] <= 'z';
    }
}
