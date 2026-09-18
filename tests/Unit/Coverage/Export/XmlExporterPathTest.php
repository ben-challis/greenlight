<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Export;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\CloverExporter;
use Greenlight\Coverage\Export\CoberturaExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Tests\Support\SimpleXml;

use function Greenlight\expect;

final readonly class XmlExporterPathTest
{
    #[Test]
    public function filePathsAreEscapedWithoutChangingTheirValue(): void
    {
        $path = '/src/A&B"\'<Coverage>.php';
        $export = $this->exportPath($path);

        expect($export['cloverDocument'])
            ->because('Clover file paths MUST be escaped as XML attributes')
            ->toContain('A&amp;B&quot;&apos;&lt;Coverage&gt;.php');
        expect($export['cloverPath'])
            ->because('the parsed Clover path MUST equal the original path')
            ->toBe($path);
        expect($export['coberturaDocument'])
            ->because('Cobertura file paths MUST be escaped as XML attributes')
            ->toContain('A&amp;B&quot;&apos;&lt;Coverage&gt;.php');
        expect($export['coberturaPath'])
            ->because('the parsed Cobertura path MUST equal its root-relative value')
            ->toBe(\ltrim($path, '/'));
    }

    /** @param non-empty-string $path */
    #[Test]
    #[DataSet('unsafePaths')]
    public function unsafePathBytesAreNormalized(string $path, string $expected): void
    {
        $export = $this->exportPath($path);

        expect($export['cloverPath'])
            ->because('Clover MUST preserve the normalized file-system path')
            ->toBe($expected);
        expect($export['coberturaPath'])
            ->because('Cobertura MUST preserve the normalized file-system path')
            ->toBe(\ltrim($expected, '/'));
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'invalid UTF-8' => [
            "/src/\xB1.php",
            "/src/\u{FFFD}.php",
        ];
        yield 'XML-forbidden control character' => [
            "/src/before\x01after.php",
            "/src/before\u{FFFD}after.php",
        ];
        yield 'XML attribute whitespace' => [
            "/src/tab\tline\nreturn\rafter.php",
            "/src/tab\tline\nreturn\rafter.php",
        ];
    }

    /**
     * @param non-empty-string $path
     *
     * @return array{
     *     cloverDocument: string,
     *     cloverPath: string,
     *     coberturaDocument: string,
     *     coberturaPath: string,
     * }
     */
    private function exportPath(string $path): array
    {
        $map = new CoverageMap([
            new FileCoverage($path, [1], []),
        ]);

        $cloverDocument = new CloverExporter()->export($map)[CloverExporter::FILE_NAME];
        $clover = new \SimpleXMLElement($cloverDocument);
        $cloverFiles = SimpleXml::xpath($clover, '/coverage/project/file');
        expect($cloverFiles)
            ->because('the Clover document MUST contain exactly one file')
            ->toHaveCount(1);

        $coberturaDocument = new CoberturaExporter()->export($map)[CoberturaExporter::FILE_NAME];
        $cobertura = new \SimpleXMLElement($coberturaDocument);
        $coberturaClasses = SimpleXml::xpath($cobertura, '/coverage/packages/package/classes/class');
        expect($coberturaClasses)
            ->because('the Cobertura document MUST contain exactly one class')
            ->toHaveCount(1);

        return [
            'cloverDocument' => $cloverDocument,
            'cloverPath' => (string) $cloverFiles[0]['name'],
            'coberturaDocument' => $coberturaDocument,
            'coberturaPath' => (string) $coberturaClasses[0]['filename'],
        ];
    }
}
