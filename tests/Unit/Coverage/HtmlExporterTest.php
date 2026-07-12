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

        Expect::that(\array_keys($pages))->toBe([
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

        Expect::that($index)->toContain('/src/A.php')
            ->and($index)->toContain('75.00%')
            ->and($index)->toContain(HtmlExporter::pageName('/src/A.php'))
            ->and($index)->toContain('<th>Total</th>')
            ->and($index)->not()->toContain('<script');
    }

    #[Test]
    public function indexShowsSummaryCardsAndCoverageBars(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [1, 2, 3], [4]),
        ]);

        $index = new HtmlExporter()->export($map)[HtmlExporter::INDEX_FILE_NAME];

        Expect::that($index)->toContain('class="cards"')
            ->and($index)->toContain('Total coverage')
            ->and($index)->toContain('class="bar"')
            ->and($index)->toContain('width:75.00%');
    }

    #[Test]
    public function indexTintsPercentagesByCoverageLevel(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/High.php', [1, 2, 3, 4, 5, 6, 7, 8, 9], [10]),
            new FileCoverage('/src/Mid.php', [1], [2]),
            new FileCoverage('/src/Low.php', [], [1]),
        ]);

        $index = new HtmlExporter()->export($map)[HtmlExporter::INDEX_FILE_NAME];

        Expect::that($index)->toContain('class="hi"')
            ->and($index)->toContain('class="mid"')
            ->and($index)->toContain('class="lo"');
    }

    #[Test]
    public function pathsAreShownRelativeToTheProjectRoot(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/proj/src/A.php', [1], []),
        ]);

        $pages = new HtmlExporter('/proj')->export($map);
        $index = $pages[HtmlExporter::INDEX_FILE_NAME];
        $filePage = $pages[HtmlExporter::pageName('/proj/src/A.php')];

        Expect::that($index)->toContain('>src/A.php<')
            ->and($index)->not()->toContain('/proj/src/A.php')
            ->and($index)->toContain(HtmlExporter::pageName('/proj/src/A.php'))
            ->and($filePage)->toContain('<h1>src/A.php</h1>');
    }

    #[Test]
    public function pathsOutsideTheProjectRootStayAbsolute(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/elsewhere/src/A.php', [1], []),
        ]);

        $index = new HtmlExporter('/proj')->export($map)[HtmlExporter::INDEX_FILE_NAME];

        Expect::that($index)->toContain('/elsewhere/src/A.php');
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

        Expect::that($page)->toContain('class="cov"')
            ->and($page)->toContain('class="unc"')
            ->and($page)->toContain('return</span> <span class="tv">$a</span> + <span class="tv">$b</span>;')
            ->and($page)->not()->toContain('<script');
    }

    #[Test]
    public function filePageSyntaxHighlightsPhpSource(): void
    {
        $fixture = (string) new \ReflectionClass(Adder::class)->getFileName();
        \assert($fixture !== '');
        $map = new CoverageMap([
            new FileCoverage($fixture, [Adder::ADD_RETURN_LINE], []),
        ]);

        $page = new HtmlExporter()->export($map)[HtmlExporter::pageName($fixture)];

        Expect::that($page)->toContain('<span class="tk">return</span>')
            ->and($page)->toContain('<span class="tk">function</span>')
            ->and($page)->not()->toContain('<script');
    }

    #[Test]
    public function unreadableSourceFallsBackToLineNumbersOnly(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/no/such/file.php', [3], [5]),
        ]);

        $page = new HtmlExporter()->export($map)[HtmlExporter::pageName('/no/such/file.php')];

        Expect::that($page)->toContain('class="cov"')
            ->and($page)->toContain('class="unc"')
            ->and($page)->toContain('<span class="num">5</span>');
    }
}
