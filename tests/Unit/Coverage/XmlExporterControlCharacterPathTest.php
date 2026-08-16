<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\CloverExporter;
use Greenlight\Coverage\Export\CoberturaExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final readonly class XmlExporterControlCharacterPathTest
{
    #[Test]
    #[DataSet('unsafePaths')]
    public function unsafePathCharactersRemainRoundTrippable(string $path, string $expected): void
    {
        $map = new CoverageMap([
            new FileCoverage($path, [1], []),
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
            ->because('Clover MUST produce a round-trippable file-system path')
            ->toBe($expected);
        Expect::that((string) $coberturaClasses[0]['filename'])
            ->because('Cobertura MUST produce a round-trippable file-system path')
            ->toBe(\ltrim($expected, '/'));
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'XML-forbidden control character' => [
            "/src/before\x01after.php",
            "/src/before\u{FFFD}after.php",
        ];
        yield 'XML attribute whitespace' => [
            "/src/tab\tline\nreturn\rafter.php",
            "/src/tab\tline\nreturn\rafter.php",
        ];
    }
}
