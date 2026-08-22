<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Export;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final readonly class HtmlExporterPathEscapingTest
{
    #[Test]
    public function filePathsCannotInjectMarkupIntoTheReport(): void
    {
        $path = '/src/"><script>coverage</script>.php';
        $pages = new HtmlExporter()->export(new CoverageMap([
            new FileCoverage($path, [1], []),
        ]));
        $escaped = '/src/&quot;&gt;&lt;script&gt;coverage&lt;/script&gt;.php';
        $index = $pages[HtmlExporter::INDEX_FILE_NAME];
        $file = $pages[HtmlExporter::pageName($path)];

        Expect::that($index)
            ->because('coverage file paths MUST remain text in the HTML index')
            ->toContain('>' . $escaped . '</a>')
            ->not()
            ->toContain('<script>coverage</script>');
        Expect::that($file)
            ->because('coverage file paths MUST remain text in the HTML file page')
            ->toContain('<title>' . $escaped . '</title>')
            ->toContain('<h1>' . $escaped . '</h1>')
            ->not()
            ->toContain('<script>coverage</script>');
    }
}
