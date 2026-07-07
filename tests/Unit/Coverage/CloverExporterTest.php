<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\CloverExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final class CloverExporterTest
{
    #[Test]
    public function documentCarriesPerFileAndProjectStatementMetrics(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [3, 7], [5]),
            new FileCoverage('/src/B.php', [2], []),
        ]);

        $xml = new \SimpleXMLElement(new CloverExporter(1234)->export($map)[CloverExporter::FILE_NAME]);

        $files = $xml->xpath('/coverage/project/file');
        \assert($files !== null);
        $firstFileLines = $xml->xpath('/coverage/project/file[1]/line');
        \assert($firstFileLines !== null);
        $firstFileMetrics = $xml->xpath('/coverage/project/file[1]/metrics');
        \assert($firstFileMetrics !== null && isset($firstFileMetrics[0]));
        $projectMetrics = $xml->xpath('/coverage/project/metrics');
        \assert($projectMetrics !== null && isset($projectMetrics[0]));

        new Expect()->that((string) $xml['generated'])->toBe('1234')
            ->and(\count($files))->toBe(2)
            ->and((string) $files[0]['name'])->toBe('/src/A.php')
            ->and(\count($firstFileLines))->toBe(3)
            ->and((string) $firstFileLines[0]['num'])->toBe('3')
            ->and((string) $firstFileLines[0]['count'])->toBe('1')
            ->and((string) $firstFileLines[1]['num'])->toBe('5')
            ->and((string) $firstFileLines[1]['count'])->toBe('0')
            ->and((string) $firstFileMetrics[0]['statements'])->toBe('3')
            ->and((string) $firstFileMetrics[0]['coveredstatements'])->toBe('2')
            ->and((string) $projectMetrics[0]['files'])->toBe('2')
            ->and((string) $projectMetrics[0]['statements'])->toBe('4')
            ->and((string) $projectMetrics[0]['coveredstatements'])->toBe('3');
    }

    #[Test]
    public function emptyMapStillProducesAParsableDocument(): void
    {
        $xml = new \SimpleXMLElement(new CloverExporter()->export(CoverageMap::empty())[CloverExporter::FILE_NAME]);

        $projectMetrics = $xml->xpath('/coverage/project/metrics');
        \assert($projectMetrics !== null && isset($projectMetrics[0]));

        new Expect()->that((string) $projectMetrics[0]['files'])->toBe('0')
            ->and((string) $projectMetrics[0]['statements'])->toBe('0');
    }
}
