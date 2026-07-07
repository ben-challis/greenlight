<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * The mergeable coverage model: per-file line coverage keyed by file path,
 * kept sorted so identical coverage always serialises identically. merge()
 * is commutative, associative, and idempotent, which lets the orchestrator
 * fold worker payloads in as they arrive, in any order, without a final
 * end-of-run merge pass.
 *
 * The wire payload is compact: under "files", each path maps to a two-element
 * list holding the covered line list and the uncovered line list.
 *
 * @internal
 */
final readonly class CoverageMap implements WireSerializable
{
    /**
     * @var array<non-empty-string, FileCoverage>
     */
    private array $files;

    /**
     * @param list<FileCoverage> $files duplicated paths are merged, covered wins
     */
    public function __construct(array $files = [])
    {
        $byPath = [];

        foreach ($files as $file) {
            $existing = $byPath[$file->file] ?? null;
            $byPath[$file->file] = $existing === null ? $file : $existing->merge($file);
        }

        \ksort($byPath, SORT_STRING);

        $this->files = $byPath;
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Normalises raw driver output: dead code lines are dropped, a status of
     * one or more becomes covered, minus one becomes uncovered, and files
     * rejected by the path filter are discarded entirely.
     */
    public static function fromRaw(RawCoverage $raw, ?PathFilter $filter = null): self
    {
        $filter ??= PathFilter::all();
        $files = [];

        foreach ($raw->lines as $path => $lines) {
            if ($path === '' || !$filter->accepts($path)) {
                continue;
            }

            $covered = [];
            $uncovered = [];

            foreach ($lines as $line => $status) {
                if ($line < 1) {
                    continue;
                }

                if ($status >= 1) {
                    $covered[] = $line;
                } elseif ($status === -1) {
                    $uncovered[] = $line;
                }
            }

            if ($covered === [] && $uncovered === []) {
                continue;
            }

            $files[] = new FileCoverage($path, $covered, $uncovered);
        }

        return new self($files);
    }

    /**
     * @return array<non-empty-string, FileCoverage> keyed by file path, sorted by path
     */
    public function files(): array
    {
        return $this->files;
    }

    public function isEmpty(): bool
    {
        return $this->files === [];
    }

    public function merge(self $other): self
    {
        return new self(\array_merge(\array_values($this->files), \array_values($other->files)));
    }

    public function coveredLineTotal(): int
    {
        $total = 0;

        foreach ($this->files as $file) {
            $total += $file->coveredLineCount();
        }

        return $total;
    }

    public function executableLineTotal(): int
    {
        $total = 0;

        foreach ($this->files as $file) {
            $total += $file->executableLineCount();
        }

        return $total;
    }

    /**
     * Total covered lines as a percentage of total executable lines. An
     * empty map counts as fully covered: there is nothing to miss.
     */
    public function totalPercentage(): float
    {
        $executable = $this->executableLineTotal();

        if ($executable === 0) {
            return 100.0;
        }

        return $this->coveredLineTotal() / $executable * 100.0;
    }

    #[\Override]
    public function toWire(): array
    {
        $files = [];

        foreach ($this->files as $path => $file) {
            $files[$path] = [$file->coveredLines, $file->uncoveredLines];
        }

        return ['files' => $files];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $files = [];

        foreach (Wire::map($payload, 'files') as $path => $lineSets) {
            if ($path === '') {
                throw InvalidWirePayload::wrongType('files', 'a map keyed by non-empty file paths', $path);
            }

            if (!\is_array($lineSets) || !\array_is_list($lineSets) || \count($lineSets) !== 2) {
                throw InvalidWirePayload::wrongType('files', 'a two-element list of line lists per file', $lineSets);
            }

            $files[] = new FileCoverage(
                $path,
                self::lineList($lineSets[0]),
                self::lineList($lineSets[1]),
            );
        }

        return new self($files);
    }

    /**
     * @return list<int>
     */
    private static function lineList(mixed $value): array
    {
        if (!\is_array($value) || !\array_is_list($value)) {
            throw InvalidWirePayload::wrongType('files', 'a list of line numbers', $value);
        }

        $lines = [];

        foreach ($value as $line) {
            if (!\is_int($line) || $line < 1) {
                throw InvalidWirePayload::wrongType('files', 'a list of positive line numbers', $line);
            }

            $lines[] = $line;
        }

        return $lines;
    }
}
