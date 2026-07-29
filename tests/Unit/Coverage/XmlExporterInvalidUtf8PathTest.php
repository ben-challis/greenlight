<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\CloverExporter;
use Greenlight\Coverage\Export\CoberturaExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final readonly class XmlExporterInvalidUtf8PathTest
{
    #[Test]
    public function invalidUtf8FilePathsAreScrubbed(): void
    {
        $map = new CoverageMap([
            new FileCoverage("/src/\xB1.php", [1], []),
        ]);

        $clover = new \SimpleXMLElement(
            new CloverExporter()->export($map)[CloverExporter::FILE_NAME],
        );
        $cloverFiles = $clover->xpath('/coverage/project/file');
        Expect::that($cloverFiles)
            ->because('the Clover document MUST contain exactly one file')
            ->toHaveCount(1);
        \assert(\is_array($cloverFiles) && isset($cloverFiles[0]));

        $cobertura = new \SimpleXMLElement(
            new CoberturaExporter()->export($map)[CoberturaExporter::FILE_NAME],
        );
        $coberturaClasses = $cobertura->xpath('/coverage/packages/package/classes/class');
        Expect::that($coberturaClasses)
            ->because('the Cobertura document MUST contain exactly one class')
            ->toHaveCount(1);
        \assert(\is_array($coberturaClasses) && isset($coberturaClasses[0]));

        Expect::that((string) $cloverFiles[0]['name'])
            ->because('Clover MUST scrub invalid UTF-8 in file-system paths')
            ->toBe("/src/\u{FFFD}.php")
            ->and((string) $coberturaClasses[0]['filename'])
            ->because('Cobertura MUST scrub invalid UTF-8 in file-system paths')
            ->toBe("src/\u{FFFD}.php");
    }
}
