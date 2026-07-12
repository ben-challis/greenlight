<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final class JsonExporterTest
{
    #[Test]
    public function documentMatchesTheDocumentedSchema(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [3, 7], [5]),
        ]);

        $decoded = \json_decode(new JsonExporter()->export($map)[JsonExporter::FILE_NAME], true, 512, \JSON_THROW_ON_ERROR);

        Expect::that($decoded)->toBe([
            'v' => 1,
            'files' => [
                '/src/A.php' => [
                    'covered' => [3, 7],
                    'uncovered' => [5],
                    'percentage' => 66.67,
                ],
            ],
            'totals' => [
                'files' => 1,
                'coveredLines' => 2,
                'executableLines' => 3,
                'percentage' => 66.67,
            ],
        ]);
    }

    #[Test]
    public function emptyMapEncodesFilesAsAnObject(): void
    {
        $json = new JsonExporter()->export(CoverageMap::empty())[JsonExporter::FILE_NAME];

        Expect::that($json)->toContain('"files":{}');
    }

    #[Test]
    public function importRoundTripsAnExportedDocument(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [3, 7], [5]),
            new FileCoverage('/src/B.php', [], [1, 2]),
        ]);

        $restored = JsonExporter::import(new JsonExporter()->export($map)[JsonExporter::FILE_NAME]);

        Expect::that($restored->toWire())->toBe($map->toWire());
    }

    #[Test]
    public function importRejectsMalformedDocuments(): void
    {
        Expect::that(static fn(): CoverageMap => JsonExporter::import('not json'))
            ->toThrow(CoverageError::class)
            ->and(static fn(): CoverageMap => JsonExporter::import('{"v":2,"files":{}}'))
            ->toThrow(CoverageError::class, '/schema version/')
            ->and(static fn(): CoverageMap => JsonExporter::import('{"v":1,"files":{"/a.php":{"covered":["x"],"uncovered":[]}}}'))
            ->toThrow(CoverageError::class, '/positive integers/');
    }
}
