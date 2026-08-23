<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Diff;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;

/** Changes absolute coverage paths below one explicit root to relative paths.
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

            if ($relative === '') {
                throw new \InvalidArgumentException(\sprintf(
                    'Coverage path "%s" does not identify a file below project root "%s".',
                    $path,
                    $root,
                ));
            }

            $files[] = new FileCoverage(
                $relative,
                $coverage->coveredLines,
                $coverage->uncoveredLines,
            );
        }

        return new CoverageMap($files);
    }
}
