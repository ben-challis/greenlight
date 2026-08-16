<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

final readonly class HtmlExporterEmptySourceTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function emptyReadableSourceProducesNoLineBoxes(): void
    {
        $path = $this->tempDirectory->path() . '/Empty.php';
        \file_put_contents($path, '');
        $map = new CoverageMap([
            new FileCoverage($path, [1], []),
        ]);

        $page = new HtmlExporter()->export($map)[HtmlExporter::pageName($path)];

        Expect::that(\is_readable($path))
            ->because('the empty source MUST be readable')
            ->toBeTrue();
        Expect::that($page)
            ->because('an empty source MUST retain a valid empty source block')
            ->toContain("<pre>\n</pre>")
            ->not()
            ->toContain('<span class="cov"><span class="num">1</span></span>');
    }
}
