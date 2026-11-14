<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Resolves a configured or command-line path against the working directory.
 *
 * An absolute path stays the same. A relative path gets the working directory
 * as its prefix. The method does not normalize the path or check the
 * filesystem.
 *
 * @internal
 */
final class WorkingDirectoryPath
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function resolve(string $path, string $workingDirectory): string
    {
        return \str_starts_with($path, '/') ? $path : \rtrim($workingDirectory, '/') . '/' . $path;
    }
}
