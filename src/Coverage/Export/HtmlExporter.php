<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;

/**
 * Static HTML coverage report with a dark, terminal-style theme.
 *
 * The index page shows summary cards (total coverage, file count, line
 * totals) and a per-file table with a coverage bar per row; each file gets
 * a page with line-by-line colouring: covered lines green, uncovered lines
 * red, non-executable lines plain, behind a sticky line-number gutter.
 * Percentages are tinted by the percentClass() thresholds. No JavaScript,
 * one inline stylesheet.
 *
 * Source is syntax highlighted by highlightedLines() using the PHP
 * tokenizer, so multi-line constructs like doc blocks and heredocs colour
 * correctly and the report works offline.
 *
 * Paths are displayed relative to the project root passed to the
 * constructor; paths outside it stay absolute. Per-file page names are
 * derived by pageName() from a hash of the absolute file path so they are
 * deterministic and filesystem safe.
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
        :root{color-scheme:dark}
        body{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:14px;background:#0d1117;color:#e6edf3;margin:0;padding:24px}
        a{color:#58a6ff;text-decoration:none}
        a:hover{text-decoration:underline}
        h1{font-size:18px;font-weight:600;margin:0 0 16px;display:flex;align-items:center;gap:10px}
        h1::before{content:"";flex:none;width:10px;height:10px;border-radius:50%;background:#3fb950;box-shadow:0 0 10px rgba(63,185,80,.7)}
        .meta{color:#8b949e;margin:0 0 20px}
        .cards{display:flex;gap:12px;flex-wrap:wrap;margin:0 0 20px}
        .card{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:14px 18px;min-width:140px}
        .card .label{color:#8b949e;font-size:11px;text-transform:uppercase;letter-spacing:.08em}
        .card .value{font-size:22px;margin-top:4px}
        table{border-collapse:separate;border-spacing:0;width:100%;background:#161b22;border:1px solid #30363d;border-radius:8px;overflow:hidden}
        th,td{padding:8px 14px;text-align:left;border-bottom:1px solid #21262d}
        td:first-child{word-break:break-all}
        td:nth-child(n+2),th:nth-child(n+2){white-space:nowrap}
        th{color:#8b949e;font-size:11px;text-transform:uppercase;letter-spacing:.08em}
        tr:last-child th{border-bottom:0;color:#e6edf3;font-size:14px;text-transform:none;letter-spacing:0}
        tr:hover td{background:#1c2128}
        .bar{width:160px;height:8px;border-radius:4px;background:#21262d;overflow:hidden}
        .bar span{display:block;height:100%}
        .hi{color:#3fb950}
        .mid{color:#d29922}
        .lo{color:#f85149}
        .bar .hi{background:#3fb950}
        .bar .mid{background:#d29922}
        .bar .lo{background:#f85149}
        pre{margin:0;padding:12px 0;background:#161b22;border:1px solid #30363d;border-radius:8px;overflow-x:auto;line-height:1.6}
        .cov,.unc{display:block}
        .cov{background:#12291d}
        .unc{background:#2d1418}
        .num{position:sticky;left:0;display:inline-block;width:56px;padding:0 14px 0 12px;text-align:right;color:#6e7681;background:#161b22;user-select:none}
        .cov .num{background:#12291d;color:#3fb950}
        .unc .num{background:#2d1418;color:#f85149}
        .tk{color:#ff7b72}
        .ts{color:#a5d6ff}
        .tv{color:#ffa657}
        .tn{color:#79c0ff}
        .tc{color:#8b949e;font-style:italic}
        CSS;

    public function __construct(private string $projectRoot = '') {}

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
            $class = $this->percentClass($file->percentage());
            $rows .= \sprintf(
                '<tr><td><a href="%s">%s</a></td><td><div class="bar"><span class="%s" style="width:%.2F%%"></span></div></td><td class="%s">%s</td><td>%d/%d</td></tr>' . "\n",
                self::pageName($path),
                $this->html($this->displayPath($path)),
                $class,
                \min(100.0, \max(0.0, $file->percentage())),
                $class,
                $this->percent($file->percentage()),
                $file->coveredLineCount(),
                $file->executableLineCount(),
            );
        }

        $totalClass = $this->percentClass($map->totalPercentage());
        $totals = \sprintf(
            '<tr><th>Total</th><th></th><th class="%s">%s</th><th>%d/%d</th></tr>' . "\n",
            $totalClass,
            $this->percent($map->totalPercentage()),
            $map->coveredLineTotal(),
            $map->executableLineTotal(),
        );

        $cards = '<div class="cards">' . "\n"
            . \sprintf(
                '<div class="card"><div class="label">Total coverage</div><div class="value %s">%s</div></div>' . "\n",
                $totalClass,
                $this->percent($map->totalPercentage()),
            )
            . \sprintf('<div class="card"><div class="label">Files</div><div class="value">%d</div></div>' . "\n", \count($map->files()))
            . \sprintf(
                '<div class="card"><div class="label">Lines covered</div><div class="value">%d/%d</div></div>' . "\n",
                $map->coveredLineTotal(),
                $map->executableLineTotal(),
            )
            . '</div>' . "\n";

        $body = '<h1>Greenlight Coverage</h1>' . "\n"
            . $cards
            . '<table>' . "\n"
            . '<tr><th>File</th><th colspan="2">Coverage</th><th>Lines</th></tr>' . "\n"
            . $rows
            . $totals
            . '</table>' . "\n";

        return $this->page('Coverage', $body);
    }

    private function filePage(FileCoverage $file): string
    {
        $displayPath = $this->displayPath($file->file);
        $body = \sprintf(
            '<h1>%s</h1>' . "\n" . '<p class="meta"><a href="%s">&larr; Back to index</a> &middot; <span class="%s">%s</span> (%d/%d lines)</p>' . "\n",
            $this->html($displayPath),
            self::INDEX_FILE_NAME,
            $this->percentClass($file->percentage()),
            $this->percent($file->percentage()),
            $file->coveredLineCount(),
            $file->executableLineCount(),
        );

        $covered = \array_fill_keys($file->coveredLines, true);
        $uncovered = \array_fill_keys($file->uncoveredLines, true);
        $source = $this->highlightedLines($file->file);
        $lastLine = $source === null
            ? \max([0, ...$file->coveredLines, ...$file->uncoveredLines])
            : \count($source);

        $body .= '<pre>' . "\n";

        for ($line = 1; $line <= $lastLine; ++$line) {
            $lineClass = isset($covered[$line]) ? 'cov' : (isset($uncovered[$line]) ? 'unc' : '');
            $content = \sprintf('<span class="num">%d</span>%s', $line, $source[$line - 1] ?? '');
            // Classed lines render as blocks, so a trailing newline would add
            // an empty line box inside the <pre>.
            $body .= $lineClass === '' ? $content . "\n" : \sprintf('<span class="%s">%s</span>', $lineClass, $content);
        }

        $body .= '</pre>' . "\n";

        return $this->page($displayPath, $body);
    }

    private function displayPath(string $path): string
    {
        $root = \rtrim($this->projectRoot, '/');

        if ($root !== '' && \str_starts_with($path, $root . '/')) {
            return \substr($path, \strlen($root) + 1);
        }

        return $path;
    }

    /**
     * Tokenizes the source and renders one escaped HTML string per line.
     * Token spans never cross a line boundary: a multi-line token is split
     * and each segment wrapped separately, so per-line cov/unc blocks stay
     * well-formed.
     *
     * @return list<string>|null
     */
    private function highlightedLines(string $path): ?array
    {
        if (!\is_file($path) || !\is_readable($path)) {
            return null;
        }

        $content = \file_get_contents($path);

        if ($content === false) {
            return null;
        }

        $lines = [''];

        foreach (\PhpToken::tokenize($content) as $token) {
            $class = $this->tokenClass($token);

            foreach (\explode("\n", $token->text) as $i => $segment) {
                if ($i > 0) {
                    $lines[] = '';
                }

                if ($segment === '') {
                    continue;
                }

                $html = $this->html($segment);
                $lines[\array_key_last($lines)] .= $class === '' ? $html : \sprintf('<span class="%s">%s</span>', $class, $html);
            }
        }

        while ($lines !== [] && $lines[\array_key_last($lines)] === '') {
            \array_pop($lines);
        }

        return $lines;
    }

    /**
     * @return ''|'tc'|'tk'|'tn'|'ts'|'tv'
     */
    private function tokenClass(\PhpToken $token): string
    {
        if ($token->is([\T_COMMENT, \T_DOC_COMMENT])) {
            return 'tc';
        }

        if ($token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE, \T_START_HEREDOC, \T_END_HEREDOC])) {
            return 'ts';
        }

        if ($token->is(\T_VARIABLE)) {
            return 'tv';
        }

        if ($token->is([\T_LNUMBER, \T_DNUMBER])) {
            return 'tn';
        }

        if ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE, \T_WHITESPACE, \T_INLINE_HTML])) {
            return '';
        }

        // Remaining named word-shaped tokens are language keywords; single
        // characters and operators fall through to the default colour.
        if ($token->id >= 256 && \preg_match('/^[A-Za-z_]+$/', $token->text) === 1) {
            return 'tk';
        }

        return '';
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

    /**
     * @return 'hi'|'mid'|'lo'
     */
    private function percentClass(float $percentage): string
    {
        return $percentage >= 90.0 ? 'hi' : ($percentage >= 50.0 ? 'mid' : 'lo');
    }

    private function percent(float $percentage): string
    {
        return \sprintf('%.2F%%', $percentage);
    }

    private function html(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES, 'UTF-8');
    }
}
