<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Expect\Expect;

final class JsonExporterSchemaDiagnosticTest
{
    #[Test]
    #[DataSet('invalidSchemaVersions')]
    public function invalidSchemaVersionsIdentifyTheSupportedVersion(string $document): void
    {
        Expect::that(static fn(): CoverageMap => JsonExporter::import($document))
            ->because('an invalid coverage schema MUST identify the supported version')
            ->toThrow(
                CoverageError::class,
                message: 'Coverage JSON document is invalid: unsupported or missing schema version, expected 1.',
            );
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function invalidSchemaVersions(): iterable
    {
        yield 'missing version' => ['{"files":{}}'];
        yield 'unsupported version' => ['{"v":2,"files":{}}'];
    }
}
