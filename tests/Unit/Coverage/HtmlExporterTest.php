<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Coverage\Adder;

final class HtmlExporterTest
{
    #[Test]
    public function producesAnIndexPlusOnePagePerFile(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [1], [2]),
            new FileCoverage('/src/B.php', [1], []),
        ]);

        $pages = new HtmlExporter()->export($map);

        new Expect()->that(\array_keys($pages))->toBe([
            HtmlExporter::INDEX_FILE_NAME,
            HtmlExporter::pageName('/src/A.php'),
            HtmlExporter::pageName('/src/B.php'),
        ]);
    }

    #[Test]
    public function indexListsEveryFileWithItsPercentageAndTheTotal(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [1, 2, 3], [4]),
        ]);

        $index = new HtmlExporter()->export($map)[HtmlExporter::INDEX_FILE_NAME];

        new Expect()->that($index)->toContain('/src/A.php')
            ->and($index)->toContain('75.00%')
            ->and($index)->toContain(HtmlExporter::pageName('/src/A.php'))
            ->and($index)->toContain('<th>Total</th>')
            ->and($index)->not()->toContain('<script');
    }

    #[Test]
    public function filePageColoursSourceLinesByCoverageStatus(): void
    {
        $fixture = (string) new \ReflectionClass(Adder::class)->getFileName();
        \assert($fixture !== '');
        $map = new CoverageMap([
            new FileCoverage($fixture, [Adder::ADD_RETURN_LINE], [Adder::NEVER_RETURN_LINE]),
        ]);

        $page = new HtmlExporter()->export($map)[HtmlExporter::pageName($fixture)];

        new Expect()->that($page)->toContain('class="cov"')
            ->and($page)->toContain('class="unc"')
            ->and($page)->toContain('return $a + $b;')
            ->and($page)->not()->toContain('<script');
    }

    #[Test]
    public function unreadableSourceFallsBackToLineNumbersOnly(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/no/such/file.php', [3], [5]),
        ]);

        $page = new HtmlExporter()->export($map)[HtmlExporter::pageName('/no/such/file.php')];

        new Expect()->that($page)->toContain('class="cov"')
            ->and($page)->toContain('class="unc"')
            ->and($page)->toContain('<span class="num">5</span>');
    }
}
