<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

/**
 * The class stores both sets as sorted unique lists with no common members.
 * If a line is in both inputs, the covered input has priority. This rule
 * makes merge() commutative, associative, and idempotent.
 *
 * @internal
 */
final readonly class FileCoverage
{
    /**
     * @var non-empty-string
     */
    public string $file;

    /**
     * @var list<positive-int>
     */
    public array $coveredLines;

    /**
     * @var list<positive-int>
     */
    public array $uncoveredLines;

    /**
     * @var list<FunctionCoverage>
     */
    public array $functions;

    /**
     * @param list<int> $coveredLines
     * @param list<int> $uncoveredLines
     * @param list<FunctionCoverage> $functions
     */
    public function __construct(
        string $file,
        array $coveredLines,
        array $uncoveredLines,
        array $functions = [],
    ) {
        if ($file === '') {
            throw new \InvalidArgumentException('Use a non-empty coverage file path.');
        }

        $this->file = $file;
        $covered = $this->normalizeLines($coveredLines);
        $coveredSet = \array_fill_keys($covered, true);
        $uncovered = [];

        foreach ($this->normalizeLines($uncoveredLines) as $line) {
            if (!isset($coveredSet[$line])) {
                $uncovered[] = $line;
            }
        }

        $this->coveredLines = $covered;
        $this->uncoveredLines = $uncovered;
        $byName = [];

        foreach ($functions as $function) {
            $existing = $byName[$function->name] ?? null;
            $byName[$function->name] = $existing instanceof FunctionCoverage ? $existing->merge($function) : $function;
        }

        \ksort($byName, \SORT_STRING);
        $this->functions = \array_values($byName);
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
     * Returns executable line hit counts in ascending line order.
     *
     * @return array<positive-int, 0|1>
     */
    public function lineHits(): array
    {
        $hits = \array_fill_keys($this->uncoveredLines, 0);

        foreach ($this->coveredLines as $line) {
            $hits[$line] = 1;
        }

        \ksort($hits);

        return $hits;
    }

    /**
     * Returns covered lines as a percentage of executable lines.
     *
     * A file with no executable lines has full coverage.
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
            [...$this->functions, ...$other->functions],
        );
    }

    public function branchTotal(): int
    {
        return \array_sum(\array_map(static fn(FunctionCoverage $function): int => $function->branchTotal(), $this->functions));
    }

    public function coveredBranchTotal(): int
    {
        return \array_sum(\array_map(static fn(FunctionCoverage $function): int => $function->coveredBranchTotal(), $this->functions));
    }

    public function pathTotal(): int
    {
        return \array_sum(\array_map(static fn(FunctionCoverage $function): int => $function->pathTotal(), $this->functions));
    }

    public function coveredPathTotal(): int
    {
        return \array_sum(\array_map(static fn(FunctionCoverage $function): int => $function->coveredPathTotal(), $this->functions));
    }

    public function branchPercentage(): float
    {
        return $this->branchTotal() === 0 ? 100.0 : $this->coveredBranchTotal() / $this->branchTotal() * 100.0;
    }

    public function pathPercentage(): float
    {
        return $this->pathTotal() === 0 ? 100.0 : $this->coveredPathTotal() / $this->pathTotal() * 100.0;
    }

    /** @return array<positive-int, list<BranchCoverage>> */
    public function branchesByLine(): array
    {
        $byLine = [];

        foreach ($this->functions as $function) {
            foreach ($function->branches as $branch) {
                $byLine[$branch->startLine][] = $branch;
            }
        }

        \ksort($byLine);

        return $byLine;
    }

    public function withFile(string $file): self
    {
        return new self($file, $this->coveredLines, $this->uncoveredLines, $this->functions);
    }

    /** @param array<int, true> $ignored */
    public function withoutLines(array $ignored): self
    {
        $covered = \array_values(\array_filter($this->coveredLines, static fn(int $line): bool => !isset($ignored[$line])));
        $uncovered = \array_values(\array_filter($this->uncoveredLines, static fn(int $line): bool => !isset($ignored[$line])));
        $functions = [];

        foreach ($this->functions as $function) {
            $filtered = $function->withoutLines($ignored);

            if ($filtered instanceof FunctionCoverage) {
                $functions[] = $filtered;
            }
        }

        return new self($this->file, $covered, $uncovered, $functions);
    }

    /**
     * @param list<int> $lines
     *
     * @return list<positive-int>
     */
    private function normalizeLines(array $lines): array
    {
        $set = [];

        foreach ($lines as $line) {
            if ($line < 1) {
                throw new \InvalidArgumentException(\sprintf('Use positive coverage line numbers. Actual value: %d.', $line));
            }

            $set[$line] = true;
        }

        $unique = \array_keys($set);
        \sort($unique);

        return $unique;
    }
}
