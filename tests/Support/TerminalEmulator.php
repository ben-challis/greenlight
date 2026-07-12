<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

/**
 * Applies the escape repertoire TtyReporter emits to a small terminal grid.
 *
 * write() consumes raw bytes and updates a grid of rows: plain text lands at
 * the cursor, `\r` returns to column 0, `\n` advances a row, `\x1b[<n>A`
 * moves the cursor up, `\x1b[2K` clears the current row, `\x1b[0J` clears
 * from the cursor to the end of the screen, and `\x1b[?25l` / `\x1b[?25h`
 * toggle cursor visibility without touching the grid. SGR colour codes
 * (`\x1b[...m`) are stripped by default; pass retainColour: true to keep
 * them inline. visibleLines() and screen() expose what the grid looks like
 * once every byte has been applied. Any other escape sequence is a bug in
 * the reporter or in this emulator, so write() throws rather than silently
 * ignoring it.
 */
final class TerminalEmulator
{
    /**
     * @var array<int, list<string>>
     */
    private array $cells = [];

    /**
     * @var array<int, string>
     */
    private array $trailing = [];

    private int $cursorRow = 0;

    private int $cursorCol = 0;

    private int $maxRow = 0;

    private string $pendingPrefix = '';

    private bool $cursorHidden = false;

    public function __construct(
        private readonly bool $retainColour = false,
    ) {
        $this->cells[0] = [];
    }

    public function write(string $bytes): void
    {
        $offset = 0;
        $length = \strlen($bytes);

        while ($offset < $length) {
            $escapeAt = \strpos($bytes, "\x1b", $offset);

            if ($escapeAt === false) {
                $this->writeText(\substr($bytes, $offset));

                break;
            }

            if ($escapeAt > $offset) {
                $this->writeText(\substr($bytes, $offset, $escapeAt - $offset));
            }

            if (\preg_match('/\G\x1b\[([0-9;?]*)([A-Za-z])/', $bytes, $matches, 0, $escapeAt) !== 1) {
                throw new \RuntimeException(\sprintf(
                    'Unrecognized escape sequence at offset %d: %s',
                    $escapeAt,
                    \addcslashes(\substr($bytes, $escapeAt, 12), "\0..\37"),
                ));
            }

            $this->applyEscape($matches[1], $matches[2]);
            $offset = $escapeAt + \strlen($matches[0]);
        }

        $this->flushPendingPrefix();
    }

    public function isCursorHidden(): bool
    {
        return $this->cursorHidden;
    }

    /**
     * @return list<string>
     */
    public function visibleLines(): array
    {
        $lines = [];

        for ($row = 0; $row <= $this->maxRow; ++$row) {
            $lines[] = \implode('', $this->cells[$row] ?? []) . ($this->trailing[$row] ?? '');
        }

        return $lines;
    }

    public function screen(): string
    {
        return \implode("\n", $this->visibleLines());
    }

    private function applyEscape(string $params, string $final): void
    {
        match (true) {
            $final === 'A' && \preg_match('/^\d*$/', $params) === 1 => $this->cursorUp($params === '' ? 1 : (int) $params),
            $final === 'K' && $params === '2' => $this->clearLine(),
            $final === 'J' && $params === '0' => $this->eraseToEndOfScreen(),
            $final === 'l' && $params === '?25' => $this->cursorHidden = true,
            $final === 'h' && $params === '?25' => $this->cursorHidden = false,
            $final === 'm' => $this->applySgr($params),
            default => throw new \RuntimeException(\sprintf('Unrecognized escape sequence: ESC[%s%s', $params, $final)),
        };
    }

    private function applySgr(string $params): void
    {
        if ($this->retainColour) {
            $this->pendingPrefix .= \sprintf("\x1b[%sm", $params);
        }
    }

    private function cursorUp(int $rows): void
    {
        $this->cursorRow = \max(0, $this->cursorRow - $rows);
    }

    private function clearLine(): void
    {
        $this->ensureRow($this->cursorRow);
        $this->cells[$this->cursorRow] = [];
        $this->trailing[$this->cursorRow] = '';
    }

    private function eraseToEndOfScreen(): void
    {
        $this->ensureRow($this->cursorRow);
        $this->cells[$this->cursorRow] = \array_slice($this->cells[$this->cursorRow], 0, $this->cursorCol);
        $this->trailing[$this->cursorRow] = '';

        for ($row = $this->cursorRow + 1; $row <= $this->maxRow; ++$row) {
            unset($this->cells[$row], $this->trailing[$row]);
        }

        $this->maxRow = $this->cursorRow;
    }

    private function writeText(string $text): void
    {
        if ($text === '') {
            return;
        }

        $chars = \preg_split('//u', $text, -1, \PREG_SPLIT_NO_EMPTY);

        if ($chars === false) {
            throw new \RuntimeException('Reporter output is not valid UTF-8.');
        }

        foreach ($chars as $char) {
            match ($char) {
                "\r" => $this->cursorCol = 0,
                "\n" => $this->newline(),
                default => $this->placeChar($char),
            };
        }
    }

    private function placeChar(string $char): void
    {
        $this->ensureRow($this->cursorRow);
        $cells = $this->cells[$this->cursorRow];

        while (\count($cells) < $this->cursorCol) {
            $cells[] = ' ';
        }

        $cells[$this->cursorCol] = $this->pendingPrefix . $char;
        $this->pendingPrefix = '';
        $this->cells[$this->cursorRow] = \array_values($cells);
        ++$this->cursorCol;
    }

    private function newline(): void
    {
        $this->flushPendingPrefix();
        ++$this->cursorRow;
        $this->cursorCol = 0;
        $this->ensureRow($this->cursorRow);
    }

    private function flushPendingPrefix(): void
    {
        if ($this->pendingPrefix === '') {
            return;
        }

        $this->trailing[$this->cursorRow] = ($this->trailing[$this->cursorRow] ?? '') . $this->pendingPrefix;
        $this->pendingPrefix = '';
    }

    private function ensureRow(int $row): void
    {
        if (!isset($this->cells[$row])) {
            $this->cells[$row] = [];
        }

        if ($row > $this->maxRow) {
            $this->maxRow = $row;
        }
    }
}
