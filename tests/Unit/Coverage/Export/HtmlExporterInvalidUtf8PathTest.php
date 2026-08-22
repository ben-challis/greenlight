<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Export;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final readonly class HtmlExporterInvalidUtf8PathTest
{
    #[Test]
    public function invalidUtf8FilePathsAreScrubbed(): void
    {
        $path = "/src/\xB1.php";
        $pages = new HtmlExporter()->export(new CoverageMap([
            new FileCoverage($path, [1], []),
        ]));

        $index = $pages[HtmlExporter::INDEX_FILE_NAME];
        $file = $pages[HtmlExporter::pageName($path)];
        $scrubbed = "/src/\u{FFFD}.php";

        Expect::that($index)
            ->because('the HTML index MUST scrub invalid UTF-8 in file-system paths')
            ->toContain($scrubbed)
            ->toMatch('//u');
        Expect::that($file)
            ->because('the HTML file page MUST scrub invalid UTF-8 in its title')
            ->toContain('<h1>' . $scrubbed . '</h1>')
            ->toMatch('//u');
    }
}
