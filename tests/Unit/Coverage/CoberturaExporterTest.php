<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\CoberturaExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final class CoberturaExporterTest
{
    #[Test]
    public function documentCarriesLineRatesAtEveryLevel(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [3, 7], [5]),
            new FileCoverage('/src/B.php', [2], []),
        ]);

        $xml = new \SimpleXMLElement(new CoberturaExporter(1234)->export($map)[CoberturaExporter::FILE_NAME]);

        $classes = $xml->xpath('/coverage/packages/package/classes/class');
        \assert($classes !== null);
        $firstClassLines = $xml->xpath('/coverage/packages/package/classes/class[1]/lines/line');
        \assert($firstClassLines !== null);

        Expect::that((string) $xml['line-rate'])->toBe('0.7500')
            ->and((string) $xml['lines-covered'])->toBe('3')
            ->and((string) $xml['lines-valid'])->toBe('4')
            ->and((string) $xml['timestamp'])->toBe('1234')
            ->and(\count($classes))->toBe(2)
            ->and((string) $classes[0]['filename'])->toBe('src/A.php')
            ->and((string) $classes[0]['line-rate'])->toBe('0.6667')
            ->and((string) $classes[1]['line-rate'])->toBe('1.0000')
            ->and(\count($firstClassLines))->toBe(3)
            ->and((string) $firstClassLines[1]['number'])->toBe('5')
            ->and((string) $firstClassLines[1]['hits'])->toBe('0');
    }

    #[Test]
    public function emptyMapReportsAFullLineRate(): void
    {
        $xml = new \SimpleXMLElement(new CoberturaExporter()->export(CoverageMap::empty())[CoberturaExporter::FILE_NAME]);

        Expect::that((string) $xml['line-rate'])->toBe('1.0000')
            ->and((string) $xml['lines-valid'])->toBe('0');
    }
}
