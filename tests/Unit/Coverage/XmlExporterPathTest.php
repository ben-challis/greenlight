<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\CloverExporter;
use Greenlight\Coverage\Export\CoberturaExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final readonly class XmlExporterPathTest
{
    #[Test]
    public function filePathsAreEscapedWithoutChangingTheirValue(): void
    {
        $path = '/src/A&B"\'<Coverage>.php';
        $map = new CoverageMap([
            new FileCoverage($path, [1], []),
        ]);

        $cloverDocument = new CloverExporter()->export($map)[CloverExporter::FILE_NAME];
        $clover = new \SimpleXMLElement($cloverDocument);
        $cloverFiles = $clover->xpath('/coverage/project/file');

        $coberturaDocument = new CoberturaExporter()->export($map)[CoberturaExporter::FILE_NAME];
        $cobertura = new \SimpleXMLElement($coberturaDocument);
        $coberturaClasses = $cobertura->xpath('/coverage/packages/package/classes/class');

        Expect::that($cloverDocument)
            ->because('Clover file paths MUST be escaped as XML attributes')
            ->toContain('A&amp;B&quot;&apos;&lt;Coverage&gt;.php')
            ->and($cloverFiles)
            ->because('the Clover document MUST contain exactly one file')
            ->toHaveCount(1);
        \assert(\is_array($cloverFiles) && isset($cloverFiles[0]));

        Expect::that((string) $cloverFiles[0]['name'])
            ->because('the parsed Clover path MUST equal the original path')
            ->toBe($path)
            ->and($coberturaDocument)
            ->because('Cobertura file paths MUST be escaped as XML attributes')
            ->toContain('A&amp;B&quot;&apos;&lt;Coverage&gt;.php')
            ->and($coberturaClasses)
            ->because('the Cobertura document MUST contain exactly one class')
            ->toHaveCount(1);
        \assert(\is_array($coberturaClasses) && isset($coberturaClasses[0]));

        Expect::that((string) $coberturaClasses[0]['filename'])
            ->because('the parsed Cobertura path MUST equal its root-relative value')
            ->toBe(\ltrim($path, '/'));
    }
}
