<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Export;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class HtmlExporterSparseSourceTest
{
    public function __construct(private TemporaryDirectory $directory) {}

    #[Test]
    #[DataSet('distantLines')]
    public function unavailableSourceShowsOnlyKnownCoverageLines(int $lastLine): void
    {
        $path = $this->directory->path() . '/missing.php';
        $map = new CoverageMap([new FileCoverage($path, [2], [$lastLine])]);
        $page = new HtmlExporter()->export($map)[HtmlExporter::pageName($path)];

        Expect::that($page)
            ->toContain('<span class="cov"><span class="num">2</span></span>')
            ->toContain(\sprintf('<span class="unc"><span class="num">%d</span></span>', $lastLine));
        Expect::that(\substr_count($page, '<span class="num">'))->toBe(2);
    }

    /** @return iterable<string, array{positive-int}> */
    public static function distantLines(): iterable
    {
        yield 'large gap' => [10_000];
        yield 'maximum line' => [\PHP_INT_MAX];
    }
}
