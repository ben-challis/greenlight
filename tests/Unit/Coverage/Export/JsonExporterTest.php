<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Export;

use Greenlight\Attribute\DataSet;
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

        Expect::that($decoded)->because('document matches the documented schema')->toBe([
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

        Expect::that($json)->because('empty map encodes files as an object')->toContain('"files":{}');
    }

    #[Test]
    public function exportRejectsFilePathsThatCannotRetainTheirIdentityInJson(): void
    {
        $map = new CoverageMap([
            new FileCoverage("/src/\xB1.php", [1], []),
            new FileCoverage("/src/\xB2.php", [2], []),
        ]);

        Expect::that(static fn(): array => new JsonExporter()->export($map))
            ->because('distinct coverage paths MUST NOT collapse to the same JSON object key')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Coverage JSON file paths MUST contain valid UTF-8.',
            );
    }

    #[Test]
    public function importRoundTripsAnExportedDocument(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [3, 7], [5]),
            new FileCoverage('/src/B.php', [], [1, 2]),
        ]);

        $restored = JsonExporter::import(new JsonExporter()->export($map)[JsonExporter::FILE_NAME]);

        Expect::that($restored->toWire())->because('import restores the exported coverage map')->toBe($map->toWire());
    }

    #[Test]
    #[DataSet('invalidDocuments')]
    public function importReportsEachInvalidDocumentExactly(string $json, string $message): void
    {
        Expect::that(static fn(): CoverageMap => JsonExporter::import($json))
            ->because('each invalid coverage JSON document MUST give its exact diagnostic')
            ->toThrow(CoverageError::class, message: $message);
    }

    #[Test]
    public function importReportsMalformedJsonWithOptionalPhpLocation(): void
    {
        Expect::that(static fn(): CoverageMap => JsonExporter::import('not json'))
            ->because('malformed coverage JSON MUST include the PHP syntax error')
            ->toThrow(
                CoverageError::class,
                // PHP 8.5 omits the location, but PHP 8.6 adds it.
                // Remove the PHP 8.5 form when PHP 8.6 is the minimum version.
                matching: '/^Coverage JSON document is invalid: Syntax error(?: near location 1:1)?$/',
            );
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'unsupported schema version' => [
            '{"v":2,"files":{}}',
            'Coverage JSON document is invalid: unsupported or missing schema version, expected 1.',
        ];
        yield 'top level is not an object' => [
            '"invalid"',
            'Coverage JSON document is invalid: use an object at the top level.',
        ];
        yield 'files is not an object' => [
            '{"v":1,"files":"invalid"}',
            'Coverage JSON document is invalid: use an object for "files".',
        ];
        yield 'file path is empty' => [
            '{"v":1,"files":{"":{}}}',
            'Coverage JSON document is invalid: map each file path in "files" to an object.',
        ];
        yield 'covered lines are not a list' => [
            '{"v":1,"files":{"/a.php":{"covered":{"line":1},"uncovered":[]}}}',
            'Coverage JSON document is invalid: use a list of line numbers for "covered" in file "/a.php".',
        ];
        yield 'covered line is not a positive integer' => [
            '{"v":1,"files":{"/a.php":{"covered":[0],"uncovered":[]}}}',
            'Coverage JSON document is invalid: use only positive integers in "covered" for file "/a.php".',
        ];
    }
}
