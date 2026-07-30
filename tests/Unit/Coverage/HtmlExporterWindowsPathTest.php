<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final readonly class HtmlExporterWindowsPathTest
{
    /**
     * @param non-empty-string $root
     * @param non-empty-string $path
     * @param non-empty-string $relative
     */
    #[Test]
    #[DataSet('windowsPaths')]
    public function windowsPathsInsideTheProjectRootAreRelative(
        string $root,
        string $path,
        string $relative,
    ): void {
        $exporter = new HtmlExporter($root);
        $pages = $exporter->export(new CoverageMap([
            new FileCoverage($path, [1], []),
        ]));

        Expect::that($pages[HtmlExporter::INDEX_FILE_NAME])
            ->because('HTML coverage MUST show Windows project paths relative to the project root')
            ->toContain('>' . $relative . '<')
            ->not()
            ->toContain($path)
            ->and($pages[HtmlExporter::pageName($path)])
            ->toContain('<h1>' . $relative . '</h1>');
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string, non-empty-string}>
     */
    public static function windowsPaths(): iterable
    {
        yield 'drive path' => [
            'C:\project',
            'C:\project\src\Example.php',
            'src\Example.php',
        ];
        yield 'case-insensitive drive path' => [
            'C:\Project',
            'c:\project\src\Example.php',
            'src\Example.php',
        ];
        yield 'UNC path' => [
            '\\\\Server\Share\project',
            '\\\\server\share\project\src\Example.php',
            'src\Example.php',
        ];
    }
}
