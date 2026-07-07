<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;

/**
 * Greenlight's own JSON coverage schema, documented in
 * docs/architecture/coverage-json.md. The document is self-describing via a
 * "v" field, carries covered and uncovered line lists plus a rounded
 * percentage per file, and aggregate totals. import() reads the same schema
 * back into a CoverageMap, which is what baseline diffing consumes.
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
        $files = [];

        foreach ($map->files() as $path => $file) {
            $files[$path] = [
                'covered' => $file->coveredLines,
                'uncovered' => $file->uncoveredLines,
                'percentage' => \round($file->percentage(), 2),
            ];
        }

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

        return [self::FILE_NAME => \json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n"];
    }

    /**
     * Reads a document produced by export() back into a CoverageMap. Totals
     * and percentages are derived data and are recomputed rather than
     * trusted.
     */
    public static function import(string $json): CoverageMap
    {
        try {
            $document = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw CoverageError::invalidJson($e->getMessage());
        }

        if (!\is_array($document)) {
            throw CoverageError::invalidJson('the top level must be an object.');
        }

        if (($document['v'] ?? null) !== self::VERSION) {
            throw CoverageError::invalidJson(\sprintf('unsupported or missing schema version, expected %d.', self::VERSION));
        }

        $rawFiles = $document['files'] ?? null;

        if (!\is_array($rawFiles)) {
            throw CoverageError::invalidJson('"files" must be an object.');
        }

        $files = [];

        foreach ($rawFiles as $path => $entry) {
            if (!\is_string($path) || $path === '' || !\is_array($entry)) {
                throw CoverageError::invalidJson('every "files" entry must map a file path to an object.');
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
            throw CoverageError::invalidJson(\sprintf('"%s" for file "%s" must be a list of line numbers.', $key, $path));
        }

        $lines = [];

        foreach ($value as $line) {
            if (!\is_int($line) || $line < 1) {
                throw CoverageError::invalidJson(\sprintf('"%s" for file "%s" must contain positive integers only.', $key, $path));
            }

            $lines[] = $line;
        }

        return $lines;
    }
}
