<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Export;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Expect\Expect;

final readonly class JsonExporterCompatibilityTest
{
    #[Test]
    public function importIgnoresAdditiveAndDerivedFields(): void
    {
        $map = JsonExporter::import(<<<'JSON'
            {
                "v": 1,
                "files": {
                    "/src/A.php": {
                        "covered": [3],
                        "uncovered": [5],
                        "percentage": 0,
                        "futureFileField": {"value": true}
                    }
                },
                "totals": {
                    "files": 99,
                    "coveredLines": 0,
                    "executableLines": 0,
                    "percentage": 0,
                    "futureTotalsField": true
                },
                "futureTopLevelField": ["value"]
            }
            JSON);

        $exported = \json_decode(
            new JsonExporter()->export($map)[JsonExporter::FILE_NAME],
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        Expect::that($map->toWire())
            ->because('coverage JSON readers MUST ignore additive fields')
            ->toBe([
                'files' => [
                    '/src/A.php' => [[3], [5]],
                ],
            ]);
        Expect::that($exported)
            ->because('coverage JSON readers MUST recalculate derived values')
            ->toBe([
                'v' => 1,
                'files' => [
                    '/src/A.php' => [
                        'covered' => [3],
                        'uncovered' => [5],
                        'percentage' => 50,
                    ],
                ],
                'totals' => [
                    'files' => 1,
                    'coveredLines' => 1,
                    'executableLines' => 2,
                    'percentage' => 50,
                ],
            ]);
    }
}
