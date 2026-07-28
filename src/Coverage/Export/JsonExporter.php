<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;

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

    #[\Override]
    public function export(CoverageMap $map): array
    {

        $files = \array_map(fn($file) => [
            'covered' => $file->coveredLines,
            'uncovered' => $file->uncoveredLines,
            'percentage' => \round($file->percentage(), 2),
        ], $map->files());

        $document = [
            'v' => self::VERSION,
            'files' => (object) $files,
            'totals' => [
                'files' => \count($map->files()),
                'coveredLines' => $map->coveredLineTotal(),
                'executableLines' => $map->executableLineTotal(),
                'percentage' => \round($map->totalPercentage(), 2),
            ],
        ];

        return [self::FILE_NAME => \json_encode(
            $document,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
        ) . "\n"];
    }

    /**
     * Reads a document from export() into a CoverageMap. The method calculates
     * totals and percentages because export() derives these values.
     */
    public static function import(string $json): CoverageMap
    {
        try {
            $document = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw CoverageError::invalidJson($e->getMessage());
        }

        if (!\is_array($document)) {
            throw CoverageError::invalidJson('use an object at the top level.');
        }

        if (($document['v'] ?? null) !== self::VERSION) {
            throw CoverageError::invalidJson(\sprintf('unsupported or missing schema version, expected %d.', self::VERSION));
        }

        $rawFiles = $document['files'] ?? null;

        if (!\is_array($rawFiles)) {
            throw CoverageError::invalidJson('use an object for "files".');
        }

        $files = [];

        foreach ($rawFiles as $path => $entry) {
            if (!\is_string($path) || $path === '' || !\is_array($entry)) {
                throw CoverageError::invalidJson('map each file path in "files" to an object.');
            }

            $files[] = new FileCoverage(
                $path,
                self::lineList($entry, 'covered', $path),
                self::lineList($entry, 'uncovered', $path),
            );
        }

        return new CoverageMap($files);
    }

    /**
     * @param array<mixed> $entry
     *
     * @return list<int>
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
}
