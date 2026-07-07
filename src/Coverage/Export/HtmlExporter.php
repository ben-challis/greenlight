<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;

/**
 * Static HTML coverage report.
 *
 * The report is an index page listing every file with its line percentage,
 * and one page per file with line-by-line colouring: covered lines green,
 * uncovered lines red, non-executable lines plain. No JavaScript, minimal
 * inline CSS.
 *
 * Per-file page names are derived by pageName() from a hash of the file path
 * so they are deterministic and filesystem safe.
 *
 * When a source file is unreadable the page falls back to line numbers and
 * statuses only.
 *
 * @internal
 */
final readonly class HtmlExporter implements CoverageExporter
{
    public const string INDEX_FILE_NAME = 'index.html';

    private const string CSS = <<<'CSS'
        body{font-family:monospace;margin:1.5em}
        table{border-collapse:collapse}
        td,th{padding:.2em .8em;text-align:left;border-bottom:1px solid #ddd}
        pre{margin:0}
        .cov{background:#d6f5d6}
        .unc{background:#f8d7da}
        .num{color:#888;display:inline-block;width:4em;text-align:right;padding-right:1em}
        CSS;

    #[\Override]
    public function export(CoverageMap $map): array
    {
        $pages = [self::INDEX_FILE_NAME => $this->indexPage($map)];

        foreach ($map->files() as $path => $file) {
            $pages[self::pageName($path)] = $this->filePage($file);
        }

        return $pages;
    }

    /**
     * @param non-empty-string $path
     *
     * @return non-empty-string
     */
    public static function pageName(string $path): string
    {
        return 'file-' . \md5($path) . '.html';
    }

    private function indexPage(CoverageMap $map): string
    {
        $rows = '';

        foreach ($map->files() as $path => $file) {
            $rows .= \sprintf(
                '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%d/%d</td></tr>' . "\n",
                self::pageName($path),
                $this->html($path),
                $this->percent($file->percentage()),
                $file->coveredLineCount(),
                $file->executableLineCount(),
            );
        }

        $totals = \sprintf(
            '<tr><th>Total</th><th>%s</th><th>%d/%d</th></tr>' . "\n",
            $this->percent($map->totalPercentage()),
            $map->coveredLineTotal(),
            $map->executableLineTotal(),
        );

        $body = '<h1>Coverage</h1>' . "\n"
            . '<table>' . "\n"
            . '<tr><th>File</th><th>Coverage</th><th>Lines</th></tr>' . "\n"
            . $rows
            . $totals
            . '</table>' . "\n";

        return $this->page('Coverage', $body);
    }

    private function filePage(FileCoverage $file): string
    {
        $body = \sprintf(
            '<h1>%s</h1>' . "\n" . '<p><a href="%s">Back to index</a> &middot; %s (%d/%d lines)</p>' . "\n",
            $this->html($file->file),
            self::INDEX_FILE_NAME,
            $this->percent($file->percentage()),
            $file->coveredLineCount(),
            $file->executableLineCount(),
        );

        $covered = \array_fill_keys($file->coveredLines, true);
        $uncovered = \array_fill_keys($file->uncoveredLines, true);
        $source = $this->sourceLines($file->file);
        $lastLine = $source === null
            ? \max([0, ...$file->coveredLines, ...$file->uncoveredLines])
            : \count($source);

        $body .= '<pre>' . "\n";

        for ($line = 1; $line <= $lastLine; ++$line) {
            $class = isset($covered[$line]) ? 'cov' : (isset($uncovered[$line]) ? 'unc' : '');
            $text = $source[$line - 1] ?? '';
            $content = \sprintf('<span class="num">%d</span>%s', $line, $this->html($text));
            $body .= $class === '' ? $content . "\n" : \sprintf('<span class="%s">%s</span>', $class, $content) . "\n";
        }

        $body .= '</pre>' . "\n";

        return $this->page($file->file, $body);
    }

    /**
     * @return list<string>|null
     */
    private function sourceLines(string $path): ?array
    {
        if (!\is_file($path) || !\is_readable($path)) {
            return null;
        }

        $content = \file_get_contents($path);

        if ($content === false) {
            return null;
        }

        return \explode("\n", \rtrim($content, "\n"));
    }

    private function page(string $title, string $body): string
    {
        return '<!DOCTYPE html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<title>' . $this->html($title) . '</title>' . "\n"
            . '<style>' . "\n" . self::CSS . "\n" . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . $body
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    private function percent(float $percentage): string
    {
        return \sprintf('%.2F%%', $percentage);
    }

    private function html(string $value): string
    {
        return \htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
