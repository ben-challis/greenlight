<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Export;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use JsonSchema\Validator;

final readonly class JsonExporterJsonSchemaTest
{
    #[Test]
    public function exportedDocumentsMatchTheShippedSchema(): void
    {
        $exporter = new JsonExporter();
        $documents = [
            'populated map' => $exporter->export(new CoverageMap([
                new FileCoverage('/project/src/A.php', [3, 7], [5]),
            ]))[JsonExporter::FILE_NAME],
            'path with line feed' => $exporter->export(new CoverageMap([
                new FileCoverage("/project/src/Line\nBreak.php", [1], []),
            ]))[JsonExporter::FILE_NAME],
            'empty map' => $exporter->export(CoverageMap::empty())[JsonExporter::FILE_NAME],
        ];

        foreach ($documents as $case => $json) {
            Expect::that($this->isValid($json))
                ->because($case . ' MUST match the shipped coverage schema')
                ->toBeTrue();
        }
    }

    #[Test]
    #[DataSet('invalidDocuments')]
    public function invalidProducerDocumentsDoNotMatchTheSchema(string $json): void
    {
        Expect::that($this->isValid($json))
            ->because('an invalid producer document MUST NOT match the coverage schema')
            ->toBeFalse();
    }

    #[Test]
    public function additiveFieldsMatchTheVersionOneSchema(): void
    {
        $json = <<<'JSON'
            {
                "v": 1,
                "files": {
                    "/project/src/A.php": {
                        "covered": [1],
                        "uncovered": [],
                        "percentage": 100,
                        "branchCoverage": 100
                    }
                },
                "totals": {
                    "files": 1,
                    "coveredLines": 1,
                    "executableLines": 1,
                    "percentage": 100,
                    "coveredBranches": 1
                },
                "producer": "future-greenlight"
            }
            JSON;

        Expect::that($this->isValid($json))
            ->because('version 1 MUST permit additive fields')
            ->toBeTrue();
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'unsupported version' => [
            '{"v":2,"files":{},"totals":{"files":0,"coveredLines":0,"executableLines":0,"percentage":100}}',
        ];
        yield 'missing totals' => [
            '{"v":1,"files":{}}',
        ];
        yield 'zero line number' => [
            '{"v":1,"files":{"/a.php":{"covered":[0],"uncovered":[],"percentage":100}},"totals":{"files":1,"coveredLines":1,"executableLines":1,"percentage":100}}',
        ];
        yield 'duplicate line number' => [
            '{"v":1,"files":{"/a.php":{"covered":[1,1],"uncovered":[],"percentage":100}},"totals":{"files":1,"coveredLines":2,"executableLines":2,"percentage":100}}',
        ];
    }

    private function isValid(string $json): bool
    {
        $document = \json_decode($json, flags: \JSON_THROW_ON_ERROR);
        $validator = new Validator();
        $validator->validate($document, $this->schema());

        return $validator->isValid();
    }

    private function schema(): object
    {
        return (object) ['$ref' => 'file://' . \dirname(__DIR__, 4) . '/resources/schema/coverage-v1.schema.json'];
    }
}
