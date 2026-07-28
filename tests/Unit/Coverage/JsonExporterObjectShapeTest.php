<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Expect\Expect;

final readonly class JsonExporterObjectShapeTest
{
    #[Test]
    #[DataSet('invalidObjectShapes')]
    public function importRejectsInvalidObjectShapes(string $json, string $message): void
    {
        Expect::that(
            static fn(): CoverageMap => JsonExporter::import($json),
        )
            ->because('the coverage JSON schema requires object-shaped file maps and entries')
            ->toThrow(
                CoverageError::class,
                message: $message,
            );
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function invalidObjectShapes(): iterable
    {
        yield 'file map is a list' => [
            '{"v":1,"files":[]}',
            'Coverage JSON document is invalid: use an object for "files".',
        ];
        yield 'file path becomes an integer key' => [
            '{"v":1,"files":{"0":{"covered":[],"uncovered":[]}}}',
            'Coverage JSON document is invalid: map each file path in "files" to an object.',
        ];
        yield 'file entry is a list' => [
            '{"v":1,"files":{"/a.php":[]}}',
            'Coverage JSON document is invalid: map each file path in "files" to an object.',
        ];
    }
}
