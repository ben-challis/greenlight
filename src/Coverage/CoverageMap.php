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
 * merge() is commutative, associative, and idempotent. Thus, the orchestrator
 * can merge worker payloads in all arrival orders. It does not require a final
 * merge operation at the end of a run.
 *
 * The wire payload is compact. Under "files", each path maps to a two-item
 * list. The covered line list is first. The uncovered line list is second.
 *
 * @internal
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
    public function __construct(array $files = [], public bool $branchCoverage = false)
    {
        $byPath = [];

        foreach ($files as $file) {
            $existing = $byPath[$file->file] ?? null;
            $byPath[$file->file] = $existing === null ? $file : $existing->merge($file);
        }

        \ksort($byPath, \SORT_STRING);

        $this->files = $byPath;
    }

    public static function empty(bool $branchCoverage = false): self
    {
        return new self(branchCoverage: $branchCoverage);
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
        if ($this->branchCoverage !== $other->branchCoverage) {
            throw new \LogicException('Cannot merge line-only coverage with branch coverage.');
        }

        return new self(\array_merge(\array_values($this->files), \array_values($other->files)), $this->branchCoverage);
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

    public function uncoveredLineTotal(): int
    {
        return $this->executableLineTotal() - $this->coveredLineTotal();
    }

    public function branchTotal(): int
    {
        return \array_sum(\array_map(static fn(FileCoverage $file): int => $file->branchTotal(), $this->files));
    }

    public function coveredBranchTotal(): int
    {
        return \array_sum(\array_map(static fn(FileCoverage $file): int => $file->coveredBranchTotal(), $this->files));
    }

    public function uncoveredBranchTotal(): int
    {
        return $this->branchTotal() - $this->coveredBranchTotal();
    }

    public function totalBranchPercentage(): float
    {
        return $this->branchTotal() === 0 ? 100.0 : $this->coveredBranchTotal() / $this->branchTotal() * 100.0;
    }

    public function pathTotal(): int
    {
        return \array_sum(\array_map(static fn(FileCoverage $file): int => $file->pathTotal(), $this->files));
    }

    public function coveredPathTotal(): int
    {
        return \array_sum(\array_map(static fn(FileCoverage $file): int => $file->coveredPathTotal(), $this->files));
    }

    public function totalPathPercentage(): float
    {
        return $this->pathTotal() === 0 ? 100.0 : $this->coveredPathTotal() / $this->pathTotal() * 100.0;
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

    /** @return array<string, mixed> */
    public function toWire(): array
    {

        if (!$this->branchCoverage) {
            $files = \array_map(fn($file) => [$file->coveredLines, $file->uncoveredLines], $this->files);

            return ['files' => $files];
        }

        $files = \array_map(static fn(FileCoverage $file): array => [
            'covered' => $file->coveredLines,
            'uncovered' => $file->uncoveredLines,
            'functions' => \array_map(static fn(FunctionCoverage $function): array => $function->toWire(), $file->functions),
        ], $this->files);

        return ['branchCoverage' => true, 'files' => $files];
    }

    /**
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
    public static function fromWire(array $payload): static
    {
        $branchCoverage = ($payload['branchCoverage'] ?? false) === true;
        $files = [];

        foreach (Wire::map($payload, 'files') as $path => $lineSets) {
            if ($path === '') {
                throw InvalidWirePayload::wrongType('files', 'a map keyed by non-empty file paths', $path);
            }

            if ($branchCoverage) {
                if (!\is_array($lineSets) || \array_is_list($lineSets)) {
                    throw InvalidWirePayload::wrongType('files', 'a map of branch coverage file objects', $lineSets);
                }

                $files[] = new FileCoverage(
                    $path,
                    self::lineList($lineSets['covered'] ?? null),
                    self::lineList($lineSets['uncovered'] ?? null),
                    self::functionList($lineSets['functions'] ?? null),
                );

                continue;
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

        return new self($files, $branchCoverage);
    }

    /**
     * @return list<FunctionCoverage>
     * @throws InvalidWirePayload
     */
    private static function functionList(mixed $value): array
    {
        if (!\is_array($value) || !\array_is_list($value)) {
            throw InvalidWirePayload::wrongType('functions', 'a list of function coverage objects', $value);
        }

        $functions = [];

        foreach ($value as $function) {
            if (!\is_array($function) || \array_is_list($function) || !\is_string($function['name'] ?? null) || $function['name'] === '') {
                throw InvalidWirePayload::wrongType('functions', 'a list of named function coverage objects', $function);
            }

            $functions[] = new FunctionCoverage(
                $function['name'],
                self::branchList($function['branches'] ?? null),
                self::pathList($function['paths'] ?? null),
            );
        }

        return $functions;
    }

    /**
     * @return list<BranchCoverage>
     * @throws InvalidWirePayload
     */
    private static function branchList(mixed $value): array
    {
        if (!\is_array($value) || !\array_is_list($value)) {
            throw InvalidWirePayload::wrongType('branches', 'a list of branch coverage objects', $value);
        }

        $branches = [];

        foreach ($value as $branch) {
            if (!\is_array($branch) || \array_is_list($branch)) {
                throw InvalidWirePayload::wrongType('branches', 'a list of branch coverage objects', $branch);
            }

            foreach (['id', 'startLine', 'endLine'] as $key) {
                if (!\is_int($branch[$key] ?? null)) {
                    throw InvalidWirePayload::wrongType($key, 'an integer', $branch[$key] ?? null);
                }
            }

            if (!\is_bool($branch['covered'] ?? null) || !\is_array($branch['exits'] ?? null) || !\array_is_list($branch['exits'])) {
                throw InvalidWirePayload::wrongType('branches', 'branch hit state and exit lists', $branch);
            }

            $exits = [];

            foreach ($branch['exits'] as $exit) {
                if (!\is_array($exit) || !\is_int($exit['id'] ?? null) || !\is_bool($exit['covered'] ?? null)) {
                    throw InvalidWirePayload::wrongType('exits', 'a list of branch exit coverage objects', $exit);
                }

                $exits[] = new BranchExitCoverage($exit['id'], $exit['covered']);
            }

            $branches[] = new BranchCoverage(
                $branch['id'],
                $branch['startLine'],
                $branch['endLine'],
                $branch['covered'],
                $exits,
            );
        }

        return $branches;
    }

    /**
     * @return list<PathCoverage>
     * @throws InvalidWirePayload
     */
    private static function pathList(mixed $value): array
    {
        if (!\is_array($value) || !\array_is_list($value)) {
            throw InvalidWirePayload::wrongType('paths', 'a list of path coverage objects', $value);
        }

        $paths = [];

        foreach ($value as $path) {
            if (!\is_array($path) || !\is_array($path['branches'] ?? null) || !\array_is_list($path['branches']) || $path['branches'] === [] || !\is_bool($path['covered'] ?? null)) {
                throw InvalidWirePayload::wrongType('paths', 'a list of covered branch sequences', $path);
            }

            $branches = [];

            foreach ($path['branches'] as $branch) {
                if (!\is_int($branch) || $branch < 0) {
                    throw InvalidWirePayload::wrongType('paths', 'a list of nonnegative branch IDs', $branch);
                }

                $branches[] = $branch;
            }

            $paths[] = new PathCoverage($branches, $path['covered']);
        }

        return $paths;
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
