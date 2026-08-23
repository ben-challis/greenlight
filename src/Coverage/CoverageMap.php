<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * The map sorts files by path. Thus, identical coverage always has identical
 * serialized data.
 *
 * `merge()` is commutative, associative, and idempotent. Thus, the orchestrator
 * can merge worker payloads in all arrival orders. It does not require a final
 * merge operation at the end of a run.
 *
 * The wire payload is compact. Under "files", each path maps to a two-item
 * list. The covered line list is first. The uncovered line list is second.
 *
 */
final readonly class CoverageMap
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

        \ksort($byPath, \SORT_STRING);

        $this->files = $byPath;
    }

    public static function empty(): self
    {
        return new self();
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
     * Returns total covered lines as a percentage of executable lines.
     *
     * An empty map has full coverage because it has no executable lines.
     */
    public function totalPercentage(): float
    {
        $executable = $this->executableLineTotal();

        if ($executable === 0) {
            return 100.0;
        }

        return $this->coveredLineTotal() / $executable * 100.0;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {

        $files = \array_map(fn($file) => [$file->coveredLines, $file->uncoveredLines], $this->files);

        return ['files' => $files];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
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
     * @throws WireCommunicationFailed
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
