<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Diff;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;

/**
 * Normalizes absolute coverage paths against explicit project roots.
 *
 * @internal
 */
final class ProjectRootNormalizer
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function normalize(CoverageMap $map, string $projectRoot): CoverageMap
    {
        if (!\str_starts_with($projectRoot, '/')) {
            throw new \InvalidArgumentException('The project root must be an absolute path.');
        }

        $root = $projectRoot === '/' ? '/' : \rtrim($projectRoot, '/');
        $prefix = $root === '/' ? '/' : $root . '/';
        $files = [];

        foreach ($map->files() as $path => $coverage) {
            if (!\str_starts_with($path, $prefix) || $path === $root) {
                throw new \InvalidArgumentException(\sprintf(
                    'Coverage path "%s" is not below project root "%s".',
                    $path,
                    $root,
                ));
            }

            $relative = \substr($path, \strlen($prefix));

            $files[] = new FileCoverage(
                $relative,
                $coverage->coveredLines,
                $coverage->uncoveredLines,
            );
        }

        return new CoverageMap($files);
    }

    /** Changes paths below one source root to paths below one target root. */
    public static function relocate(CoverageMap $map, string $sourceRoot, string $targetRoot): CoverageMap
    {
        if (!\str_starts_with($targetRoot, '/')) {
            throw new \InvalidArgumentException('The target project root must be an absolute path.');
        }

        $relative = self::normalize($map, $sourceRoot);
        $root = $targetRoot === '/' ? '' : \rtrim($targetRoot, '/');
        $files = [];

        foreach ($relative->files() as $path => $coverage) {
            $files[] = new FileCoverage(
                $root . '/' . $path,
                $coverage->coveredLines,
                $coverage->uncoveredLines,
            );
        }

        return new CoverageMap($files);
    }

    public static function requireAbsolutePaths(CoverageMap $map): void
    {
        foreach ($map->files() as $path => $_coverage) {
            if (self::isAbsolute($path)) {
                continue;
            }

            throw new \InvalidArgumentException(\sprintf(
                'Coverage JSON requires an absolute file path. Received "%s".',
                $path,
            ));
        }
    }

    private static function isAbsolute(string $path): bool
    {
        return \str_starts_with($path, '/')
            || \str_starts_with($path, '\\\\')
            || \preg_match('~\A[A-Za-z]:[\\\\/]~D', $path) === 1;
    }
}
