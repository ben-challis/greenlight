<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\BranchCoverage;
use Greenlight\Coverage\BranchExitCoverage;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\FunctionCoverage;
use Greenlight\Coverage\PathCoverage;

/**
 * Uses the Greenlight JSON coverage schema from docs/architecture/coverage-json.md.
 *
 * import() reads the schema into a CoverageMap for a baseline comparison.
 *
 * @internal
 */
final readonly class JsonExporter implements CoverageExporter
{
    public const string FILE_NAME = 'coverage.json';

    public const int VERSION = 1;
    public const int BRANCH_VERSION = 2;

    #[\Override]
    public function export(CoverageMap $map): array
    {
        $files = [];

        foreach ($map->files() as $path => $file) {
            if (\preg_match('//u', $path) !== 1) {
                throw new \InvalidArgumentException('Coverage JSON file paths MUST contain valid UTF-8.');
            }

            $files[$path] = [
                'covered' => $file->coveredLines,
                'uncovered' => $file->uncoveredLines,
                'percentage' => \round($file->percentage(), 2),
            ];

            if ($map->branchCoverage) {
                $files[$path]['functions'] = \array_map(
                    static fn(FunctionCoverage $function): array => $function->toWire(),
                    $file->functions,
                );
                $files[$path]['coveredBranches'] = $file->coveredBranchTotal();
                $files[$path]['branches'] = $file->branchTotal();
                $files[$path]['branchPercentage'] = \round($file->branchPercentage(), 2);
                $files[$path]['coveredPaths'] = $file->coveredPathTotal();
                $files[$path]['paths'] = $file->pathTotal();
                $files[$path]['pathPercentage'] = \round($file->pathPercentage(), 2);
            }
        }

        $document = [
            'v' => $map->branchCoverage ? self::BRANCH_VERSION : self::VERSION,
            'files' => (object) $files,
            'totals' => [
                'files' => \count($map->files()),
                'coveredLines' => $map->coveredLineTotal(),
                'executableLines' => $map->executableLineTotal(),
                'percentage' => \round($map->totalPercentage(), 2),
            ],
        ];

        if ($map->branchCoverage) {
            $document['totals']['coveredBranches'] = $map->coveredBranchTotal();
            $document['totals']['branches'] = $map->branchTotal();
            $document['totals']['branchPercentage'] = \round($map->totalBranchPercentage(), 2);
            $document['totals']['coveredPaths'] = $map->coveredPathTotal();
            $document['totals']['paths'] = $map->pathTotal();
            $document['totals']['pathPercentage'] = \round($map->totalPathPercentage(), 2);
        }

        return [self::FILE_NAME => \json_encode(
            $document,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        ) . "\n"];
    }

    /**
     * Reads a document from export() into a CoverageMap. The method calculates
     * totals and percentages because export() derives these values.
     * @throws CoverageError
     */
    public static function import(string $json): CoverageMap
    {
        try {
            $document = \json_decode($json, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw CoverageError::invalidJson($e->getMessage());
        }

        if (!$document instanceof \stdClass) {
            throw CoverageError::invalidJson('use an object at the top level.');
        }

        $version = $document->v ?? null;

        if ($version !== self::VERSION && $version !== self::BRANCH_VERSION) {
            throw CoverageError::invalidJson(\sprintf(
                'unsupported or missing schema version, expected %d or %d.',
                self::VERSION,
                self::BRANCH_VERSION,
            ));
        }

        $rawFiles = $document->files ?? null;

        if (!$rawFiles instanceof \stdClass) {
            throw CoverageError::invalidJson('use an object for "files".');
        }

        $files = [];

        foreach (\get_object_vars($rawFiles) as $path => $entry) {
            if (!\is_string($path) || $path === '' || !$entry instanceof \stdClass) {
                throw CoverageError::invalidJson('map each file path in "files" to an object.');
            }

            $entry = \get_object_vars($entry);
            $files[] = new FileCoverage(
                $path,
                self::lineList($entry, 'covered', $path),
                self::lineList($entry, 'uncovered', $path),
                $version === self::BRANCH_VERSION ? self::functionList($entry, $path) : [],
            );
        }

        return new CoverageMap($files, $version === self::BRANCH_VERSION);
    }

    /**
     * @param array<mixed> $entry
     *
     * @return list<int>
     * @throws CoverageError
     */
    private static function lineList(array $entry, string $key, string $path): array
    {
        $value = $entry[$key] ?? null;

        if (!\is_array($value) || !\array_is_list($value)) {
            throw CoverageError::invalidJson(\sprintf('use a list of line numbers for "%s" in file "%s".', $key, $path));
        }

        $lines = [];

        foreach ($value as $line) {
            if (!\is_int($line) || $line < 1) {
                throw CoverageError::invalidJson(\sprintf('use only positive integers in "%s" for file "%s".', $key, $path));
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param array<mixed> $entry
     * @return list<FunctionCoverage>
     * @throws CoverageError
     */
    private static function functionList(array $entry, string $path): array
    {
        $value = $entry['functions'] ?? null;

        if (!\is_array($value) || !\array_is_list($value)) {
            throw CoverageError::invalidJson(\sprintf('use a list of functions for file "%s".', $path));
        }

        $functions = [];

        foreach ($value as $function) {
            if (!$function instanceof \stdClass) {
                throw CoverageError::invalidJson(\sprintf('use objects in the function list for file "%s".', $path));
            }

            $function = \get_object_vars($function);
            $name = $function['name'] ?? null;

            if (!\is_string($name) || $name === '') {
                throw CoverageError::invalidJson(\sprintf('use a non-empty function name for file "%s".', $path));
            }

            try {
                $functions[] = new FunctionCoverage(
                    $name,
                    self::branchList($function, $path, $name),
                    self::pathList($function, $path, $name),
                );
            } catch (\LogicException $error) {
                throw CoverageError::invalidJson($error->getMessage());
            }
        }

        return $functions;
    }

    /**
     * @param array<mixed> $function
     * @return list<BranchCoverage>
     * @throws CoverageError
     */
    private static function branchList(array $function, string $path, string $name): array
    {
        $value = $function['branches'] ?? null;

        if (!\is_array($value) || !\array_is_list($value)) {
            throw CoverageError::invalidJson(\sprintf('use a branch list for function "%s" in file "%s".', $name, $path));
        }

        $branches = [];

        foreach ($value as $branch) {
            if (!$branch instanceof \stdClass) {
                throw CoverageError::invalidJson(\sprintf('use branch objects for function "%s" in file "%s".', $name, $path));
            }

            $branch = \get_object_vars($branch);
            $id = self::integer($branch, 'id', $path, $name);
            $startLine = self::integer($branch, 'startLine', $path, $name);
            $endLine = self::integer($branch, 'endLine', $path, $name);
            $covered = $branch['covered'] ?? null;
            $rawExits = $branch['exits'] ?? null;

            if (!\is_bool($covered) || !\is_array($rawExits) || !\array_is_list($rawExits)) {
                throw CoverageError::invalidJson(\sprintf('use branch hit state and exits for function "%s" in file "%s".', $name, $path));
            }

            $exits = [];

            foreach ($rawExits as $exit) {
                if (!$exit instanceof \stdClass) {
                    throw CoverageError::invalidJson(\sprintf('use branch exit objects for function "%s" in file "%s".', $name, $path));
                }

                $exit = \get_object_vars($exit);
                $exitCovered = $exit['covered'] ?? null;

                if (!\is_bool($exitCovered)) {
                    throw CoverageError::invalidJson(\sprintf('use a branch exit hit state for function "%s" in file "%s".', $name, $path));
                }

                $exits[] = new BranchExitCoverage(
                    self::integer($exit, 'id', $path, $name),
                    $exitCovered,
                );
            }

            try {
                $branches[] = new BranchCoverage($id, $startLine, $endLine, $covered, $exits);
            } catch (\InvalidArgumentException $error) {
                throw CoverageError::invalidJson($error->getMessage());
            }
        }

        return $branches;
    }

    /**
     * @param array<mixed> $function
     * @return list<PathCoverage>
     * @throws CoverageError
     */
    private static function pathList(array $function, string $path, string $name): array
    {
        $value = $function['paths'] ?? null;

        if (!\is_array($value) || !\array_is_list($value)) {
            throw CoverageError::invalidJson(\sprintf('use a path list for function "%s" in file "%s".', $name, $path));
        }

        $paths = [];

        foreach ($value as $rawPath) {
            if (!$rawPath instanceof \stdClass) {
                throw CoverageError::invalidJson(\sprintf('use path objects for function "%s" in file "%s".', $name, $path));
            }

            $rawPath = \get_object_vars($rawPath);
            $branches = $rawPath['branches'] ?? null;
            $covered = $rawPath['covered'] ?? null;

            if (!\is_array($branches) || !\array_is_list($branches) || $branches === [] || !\is_bool($covered)) {
                throw CoverageError::invalidJson(\sprintf('use a non-empty branch sequence and hit state for function "%s" in file "%s".', $name, $path));
            }

            foreach ($branches as $branch) {
                if (!\is_int($branch) || $branch < 0) {
                    throw CoverageError::invalidJson(\sprintf('use nonnegative branch IDs for function "%s" in file "%s".', $name, $path));
                }
            }

            try {
                $paths[] = new PathCoverage($branches, $covered);
            } catch (\InvalidArgumentException $error) {
                throw CoverageError::invalidJson($error->getMessage());
            }
        }

        return $paths;
    }

    /**
     * @param array<mixed> $entry
     * @throws CoverageError
     */
    private static function integer(array $entry, string $key, string $path, string $name): int
    {
        $value = $entry[$key] ?? null;

        if (!\is_int($value)) {
            throw CoverageError::invalidJson(\sprintf('use an integer "%s" for function "%s" in file "%s".', $key, $name, $path));
        }

        return $value;
    }
}
