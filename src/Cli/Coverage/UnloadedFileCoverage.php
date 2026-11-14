<?php

declare(strict_types=1);

namespace Greenlight\Cli\Coverage;

use Greenlight\Coverage\Collection\PathFilter;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Adds the source files that no test loaded to a coverage map.
 *
 * A coverage driver reports only the files that PHP compiled. The complete()
 * method finds each `.php` file in the include paths that the map does not
 * contain.
 * The probe supplies the executable lines of these files as uncovered lines.
 * Thus, an untested file decreases the total percentage.
 *
 * The method compares real paths. A file that the map contains through a
 * symbolic link is not added again. An empty include path list adds no file.
 * Include paths are directories, as for the driver output filter.
 *
 * @internal
 */
final readonly class UnloadedFileCoverage
{
    public function __construct(private ExecutableLineProbe $probe) {}

    /**
     * @param list<non-empty-string> $includePaths absolute paths
     *
     * @throws CoverageError
     */
    public function complete(CoverageMap $map, array $includePaths): CoverageMap
    {
        if ($includePaths === []) {
            return $map;
        }

        $known = [];

        foreach ($map->files() as $file) {
            $known[$this->realPath($file->file) ?? $file->file] = true;
        }

        $missing = [];

        foreach ($this->sourceFiles($includePaths) as $file) {
            if (!isset($known[$file])) {
                $missing[] = $file;
            }
        }

        if ($missing === []) {
            return $map;
        }

        return $map->merge($this->probe->measure($missing));
    }

    /**
     * @param non-empty-list<non-empty-string> $includePaths
     *
     * @return list<non-empty-string> sorted real paths
     */
    private function sourceFiles(array $includePaths): array
    {
        $filter = new PathFilter($includePaths);
        $files = [];

        foreach ($includePaths as $path) {
            foreach ($this->candidates($path) as $candidate) {
                $real = $this->realPath($candidate);

                if ($real !== null && \str_ends_with($real, '.php') && $filter->accepts($real)) {
                    $files[$real] = true;
                }
            }
        }

        $sorted = \array_keys($files);
        \sort($sorted, \SORT_STRING);

        return $sorted;
    }

    /**
     * @return iterable<string>
     */
    private function candidates(string $path): iterable
    {
        if (!ErrorTrap::run(static fn() => \is_dir($path))) {
            return [];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
        } catch (\UnexpectedValueException) {
            return [];
        }

        $paths = [];

        foreach ($iterator as $candidate) {
            if (\is_string($candidate) && \str_ends_with($candidate, '.php')) {
                $paths[] = $candidate;
            }
        }

        return $paths;
    }

    /**
     * @return non-empty-string|null
     */
    private function realPath(string $path): ?string
    {
        $real = ErrorTrap::run(static fn() => \realpath($path));

        if (!\is_string($real) || $real === '' || !ErrorTrap::run(static fn() => \is_file($real))) {
            return null;
        }

        return $real;
    }
}
