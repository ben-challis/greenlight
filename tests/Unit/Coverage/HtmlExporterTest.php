<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\Coverage\Adder;

final readonly class HtmlExporterTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function producesAnIndexPlusOnePagePerFile(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [1], [2]),
            new FileCoverage('/src/B.php', [1], []),
        ]);

        $pages = new HtmlExporter()->export($map);

        Expect::that(\array_keys($pages))->because('produces an index plus one page per file')->toBe([
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
            new FileCoverage('/src/B.php', [1], [2, 3]),
        ]);

        $index = new HtmlExporter()->export($map)[HtmlExporter::INDEX_FILE_NAME];
        \preg_match_all('/<tr>.*<\/tr>/', $index, $rows);

        Expect::that($rows[0])->because('index lists every file with its percentage and the total')->toBe([
            '<tr><th>File</th><th colspan="2">Coverage</th><th>Lines</th></tr>',
            \sprintf(
                '<tr><td><a href="%s">/src/A.php</a></td><td><div class="bar"><span class="mid" style="width:75.00%%"></span></div></td><td class="mid">75.00%%</td><td>3/4</td></tr>',
                HtmlExporter::pageName('/src/A.php'),
            ),
            \sprintf(
                '<tr><td><a href="%s">/src/B.php</a></td><td><div class="bar"><span class="lo" style="width:33.33%%"></span></div></td><td class="lo">33.33%%</td><td>1/3</td></tr>',
                HtmlExporter::pageName('/src/B.php'),
            ),
            '<tr><th>Total</th><th></th><th class="mid">57.14%</th><th>4/7</th></tr>',
        ]);

        Expect::that($index)->because('index contains no scripts')->not()->toContain('<script');
    }

    #[Test]
    public function indexShowsSummaryCardsAndCoverageBars(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [1, 2, 3], [4]),
        ]);

        $index = new HtmlExporter()->export($map)[HtmlExporter::INDEX_FILE_NAME];

        Expect::that($index)->because('index shows summary cards and coverage bars')->toContain('class="cards"')
            ->toContain('Total coverage')
            ->toContain('class="bar"')
            ->toContain('width:75.00%');
    }

    #[Test]
    #[DataSet('coverageLevelBoundaries')]
    public function indexTintsPercentageAtCoverageLevelBoundaries(
        int $coveredLineCount,
        int $executableLineCount,
        string $expectedClass,
        string $expectedPercentage,
    ): void {
        $lines = \range(1, $executableLineCount);
        $map = new CoverageMap([
            new FileCoverage(
                '/src/Boundary.php',
                \array_slice($lines, 0, $coveredLineCount),
                \array_slice($lines, $coveredLineCount),
            ),
        ]);

        $index = new HtmlExporter()->export($map)[HtmlExporter::INDEX_FILE_NAME];

        Expect::that($index)
            ->because('the index MUST tint percentages at the coverage-level boundaries')
            ->toContain(\sprintf(
                '<td class="%s">%s</td><td>%d/%d</td>',
                $expectedClass,
                $expectedPercentage,
                $coveredLineCount,
                $executableLineCount,
            ));
    }

    /**
     * @return iterable<string, array{int, int, 'hi'|'mid'|'lo', string}>
     */
    public static function coverageLevelBoundaries(): iterable
    {
        yield 'high starts at 90 percent' => [9, 10, 'hi', '90.00%'];
        yield 'middle starts at 50 percent' => [1, 2, 'mid', '50.00%'];
        yield 'low is below 50 percent' => [0, 1, 'lo', '0.00%'];
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

        Expect::that($index)->because('paths are shown relative to the project root')->toContain('>src/A.php<')
            ->not()->toContain('/proj/src/A.php')
            ->toContain(HtmlExporter::pageName('/proj/src/A.php'))
            ->and($filePage)->toContain('<h1>src/A.php</h1>');
    }

    #[Test]
    public function pathsOutsideTheProjectRootStayAbsolute(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/elsewhere/src/A.php', [1], []),
        ]);

        $index = new HtmlExporter('/proj')->export($map)[HtmlExporter::INDEX_FILE_NAME];

        Expect::that($index)->because('paths outside the project root stay absolute')->toContain('/elsewhere/src/A.php');
    }

    #[Test]
    public function filePageColorsSourceLinesByCoverageStatus(): void
    {
        $fixture = (string) new \ReflectionClass(Adder::class)->getFileName();
        \assert($fixture !== '');
        $map = new CoverageMap([
            new FileCoverage($fixture, [Adder::ADD_RETURN_LINE], [Adder::NEVER_RETURN_LINE]),
        ]);

        $page = new HtmlExporter()->export($map)[HtmlExporter::pageName($fixture)];

        Expect::that($page)->because('file page colors source lines by coverage status')->toContain('class="cov"')
            ->toContain('class="unc"')
            ->toContain('return</span> <span class="tv">$a</span> + <span class="tv">$b</span>;')
            ->not()->toContain('<script');
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

        Expect::that($page)->because('file page syntax highlights PHP source')->toContain('<span class="tk">return</span>')
            ->toContain('<span class="tk">function</span>')
            ->not()->toContain('<script');
    }

    #[Test]
    public function filePageHighlightsAndEscapesStringTokens(): void
    {
        $fixture = $this->tempDirectory->path() . '/StringTokens.php';
        \file_put_contents($fixture, <<<'PHP'
            <?php

            $quoted = '<tag>&';
            $heredoc = <<<TEXT
            <inside>&
            TEXT;
            PHP);
        $map = new CoverageMap([
            new FileCoverage($fixture, [3], []),
        ]);

        $page = new HtmlExporter()->export($map)[HtmlExporter::pageName($fixture)];

        Expect::that($page)
            ->because('PHP string and heredoc tokens are highlighted without becoming HTML')
            ->toContain('<span class="ts">&#039;&lt;tag&gt;&amp;&#039;</span>')
            ->toContain('<span class="ts">&lt;inside&gt;&amp;</span>')
            ->not()->toContain('<tag>')
            ->not()->toContain('<inside>');
    }

    #[Test]
    public function unreadableSourceFallsBackToLineNumbersOnly(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/no/such/file.php', [3], [5]),
        ]);

        $page = new HtmlExporter()->export($map)[HtmlExporter::pageName('/no/such/file.php')];

        Expect::that($page)->because('unreadable source shows only line numbers')->toContain('class="cov"')
            ->toContain('class="unc"')
            ->toContain('<span class="num">5</span>');
    }
}
