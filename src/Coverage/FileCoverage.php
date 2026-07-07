<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

/**
 * Line coverage for a single file: the set of executable lines split into
 * covered and uncovered.
 *
 * Both sets are stored as sorted unique lists and are disjoint; when a line
 * appears in both inputs, covered wins. That rule makes merge() commutative,
 * associative, and idempotent.
 *
 * @internal
 */
final readonly class FileCoverage
{
    /**
     * @var list<positive-int>
     */
    public array $coveredLines;

    /**
     * @var list<positive-int>
     */
    public array $uncoveredLines;

    /**
     * @param non-empty-string $file
     * @param list<int> $coveredLines
     * @param list<int> $uncoveredLines
     */
    public function __construct(
        public string $file,
        array $coveredLines,
        array $uncoveredLines,
    ) {
        $covered = $this->normaliseLines($coveredLines);
        $coveredSet = \array_fill_keys($covered, true);
        $uncovered = [];

        foreach ($this->normaliseLines($uncoveredLines) as $line) {
            if (!isset($coveredSet[$line])) {
                $uncovered[] = $line;
            }
        }

        $this->coveredLines = $covered;
        $this->uncoveredLines = $uncovered;
    }

    public function executableLineCount(): int
    {
        return \count($this->coveredLines) + \count($this->uncoveredLines);
    }

    public function coveredLineCount(): int
    {
        return \count($this->coveredLines);
    }

    /**
     * Covered lines as a percentage of executable lines. A file with no
     * executable lines counts as fully covered: there is nothing to miss.
     */
    public function percentage(): float
    {
        $executable = $this->executableLineCount();

        if ($executable === 0) {
            return 100.0;
        }

        return \count($this->coveredLines) / $executable * 100.0;
    }

    public function merge(self $other): self
    {
        if ($other->file !== $this->file) {
            throw new \LogicException(\sprintf('Cannot merge coverage of "%s" into coverage of "%s".', $other->file, $this->file));
        }

        return new self(
            $this->file,
            \array_merge($this->coveredLines, $other->coveredLines),
            \array_merge($this->uncoveredLines, $other->uncoveredLines),
        );
    }

    /**
     * @param list<int> $lines
     *
     * @return list<positive-int>
     */
    private function normaliseLines(array $lines): array
    {
        $set = [];

        foreach ($lines as $line) {
            if ($line < 1) {
                throw new \InvalidArgumentException(\sprintf('Coverage line numbers must be positive, got %d.', $line));
            }

            $set[$line] = true;
        }

        $unique = \array_keys($set);
        \sort($unique);

        return $unique;
    }
}
