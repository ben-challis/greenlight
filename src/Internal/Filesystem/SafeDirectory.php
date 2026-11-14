<?php

declare(strict_types=1);

namespace Greenlight\Internal\Filesystem;

use Greenlight\Internal\Php\ErrorTrap;

/**
 * Creates directories without a walk through symbolic links.
 *
 * check() and create() examine each path from $directory up to a stop point.
 * Without $root, the walk stops at the first existing directory. With $root,
 * the walk examines each path up to and including $root, and ignores paths
 * outside $root. A symbolic link or a non-directory entry stops the walk.
 * create() then makes each missing directory with mode 0700, from the top
 * down.
 *
 * @internal
 */
final class SafeDirectory
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @return list<string> The missing directories, from the top down.
     * @throws DirectoryWalkError
     */
    public static function check(string $directory, ?string $root = null): array
    {
        $missing = [];
        $current = $directory;
        while ($root === null || \str_starts_with($current . '/', $root . '/')) {
            if (ErrorTrap::run(static fn() => \is_link($current))) {
                throw new DirectoryWalkError(DirectoryWalkFailure::SymbolicLink, $current);
            }
            $isDirectory = ErrorTrap::run(static fn() => \is_dir($current));
            if ($isDirectory && $root === null) {
                break;
            }
            if (!$isDirectory) {
                if (ErrorTrap::run(static fn() => \file_exists($current))) {
                    throw new DirectoryWalkError(DirectoryWalkFailure::NotADirectory, $current);
                }
                $missing[] = $current;
            }
            if ($current === $root) {
                break;
            }
            $parent = \dirname($current);
            if ($parent === $current) {
                throw new DirectoryWalkError(DirectoryWalkFailure::NoExistingAncestor, $current);
            }
            $current = $parent;
        }

        return \array_reverse($missing);
    }

    /** @throws DirectoryWalkError */
    public static function create(string $directory, ?string $root = null): void
    {
        foreach (self::check($directory, $root) as $path) {
            if (!ErrorTrap::run(static fn() => \mkdir($path, 0o700), $warning)) {
                throw new DirectoryWalkError(DirectoryWalkFailure::CreationFailed, $path, $warning);
            }
        }
    }
}
