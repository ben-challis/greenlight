<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Attribution;

use Greenlight\Coverage\BranchCoverage;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\Ignore\IgnoreScanner;
use Greenlight\Coverage\PathCoverage;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Test\TestId;

/**
 * Spools the test-to-line relation without keeping the complete relation in memory.
 *
 * @internal
 */
final class TestCoverageStore
{
    private const int LINE_VERSION = 1;
    private const int BRANCH_VERSION = 2;
    private const int LINES_PER_RECORD = 50_000;

    private readonly string $spool;

    /** @var array<string, array{ordinal: non-negative-int, id: TestId, sourceFile: string}> */
    private array $tests = [];

    /** @var array<non-empty-string, array<positive-int, true>> */
    private array $mappedLines = [];

    /** @var array<non-empty-string, array<non-empty-string, array<int<0, max>, true>>> */
    private array $mappedBranches = [];

    /** @var array<non-empty-string, array<non-empty-string, array<non-empty-string, true>>> */
    private array $mappedPaths = [];

    /** @var array<non-empty-string, array<int, true>> */
    private array $ignoredLines = [];

    private bool $closed = false;

    /** @throws CoverageError */
    private function __construct(
        string $temporaryDirectory,
        private readonly IgnoreScanner $ignoreScanner,
        private readonly bool $branchCoverage,
    ) {
        if (!ErrorTrap::run(static fn(): bool => \is_dir($temporaryDirectory))
            && !ErrorTrap::run(static fn(): bool => \mkdir($temporaryDirectory, 0o777, true), $warning)
        ) {
            throw CoverageError::artifactWriteFailed($temporaryDirectory, $warning);
        }

        $this->spool = \rtrim($temporaryDirectory, '/') . '/greenlight-test-coverage-' . \bin2hex(\random_bytes(8)) . '.jsonl';

        if (ErrorTrap::run(fn(): int|false => \file_put_contents($this->spool, ''), $warning) === false) {
            throw CoverageError::artifactWriteFailed($this->spool, $warning);
        }
    }

    /** @throws CoverageError */
    public static function open(?string $temporaryDirectory = null, bool $branchCoverage = false): self
    {
        return new self($temporaryDirectory ?? \sys_get_temp_dir(), new IgnoreScanner(), $branchCoverage);
    }

    public function registerPlan(ExecutionPlan $plan): void
    {
        foreach ($plan->entries as $ordinal => $entry) {
            $this->tests[(string) $entry->id] = [
                'ordinal' => $ordinal,
                'id' => $entry->id,
                'sourceFile' => $entry->sourceFile,
            ];
        }
    }

    /** @throws CoverageError */
    public function record(TestId $id, CoverageMap $coverage): void
    {
        $test = $this->tests[(string) $id] ?? null;

        if ($test === null) {
            throw new \LogicException(\sprintf('Coverage arrived for unknown test "%s".', $id));
        }

        foreach ($coverage->files() as $file) {
            $lines = $this->withoutIgnored($file->file, $file->coveredLines);

            foreach (\array_chunk($lines, self::LINES_PER_RECORD) as $chunk) {
                foreach ($chunk as $line) {
                    $this->mappedLines[$file->file][$line] = true;
                }

                $this->append([
                    'v' => $this->version(),
                    'type' => 'coverage',
                    'test' => $test['ordinal'],
                    'file' => $file->file,
                    'lines' => $chunk,
                ]);
            }

            foreach ($file->functions as $function) {
                $branches = \array_map(
                    static fn(BranchCoverage $branch): int => $branch->id,
                    \array_values(\array_filter($function->branches, static fn(BranchCoverage $branch): bool => $branch->covered)),
                );

                if ($branches !== []) {
                    $this->recordBranches($id, $file->file, $function->name, $branches);
                }

                $paths = \array_map(
                    static fn(PathCoverage $path): array => $path->branches,
                    \array_values(\array_filter($function->paths, static fn(PathCoverage $path): bool => $path->covered)),
                );

                if ($paths !== []) {
                    $this->recordPaths($id, $file->file, $function->name, $paths);
                }
            }
        }
    }

    /**
     * @param non-empty-string $file
     * @param non-empty-string $function
     * @param non-empty-list<int<0, max>> $branches
     * @throws CoverageError
     */
    public function recordBranches(TestId $id, string $file, string $function, array $branches): void
    {
        $test = $this->test($id);

        foreach (\array_chunk($branches, self::LINES_PER_RECORD) as $chunk) {
            foreach ($chunk as $branch) {
                $this->mappedBranches[$file][$function][$branch] = true;
            }

            $this->append([
                'v' => $this->version(),
                'type' => 'branch-coverage',
                'test' => $test['ordinal'],
                'file' => $file,
                'function' => $function,
                'branches' => $chunk,
            ]);
        }
    }

    /**
     * @param non-empty-string $file
     * @param non-empty-string $function
     * @param non-empty-list<non-empty-list<int<0, max>>> $paths
     * @throws CoverageError
     */
    public function recordPaths(TestId $id, string $file, string $function, array $paths): void
    {
        $test = $this->test($id);

        foreach (\array_chunk($paths, self::LINES_PER_RECORD) as $chunk) {
            foreach ($chunk as $path) {
                $this->mappedPaths[$file][$function][\implode(':', $path)] = true;
            }

            $this->append([
                'v' => $this->version(),
                'type' => 'path-coverage',
                'test' => $test['ordinal'],
                'file' => $file,
                'function' => $function,
                'paths' => $chunk,
            ]);
        }
    }

    /** @throws CoverageError */
    public function write(string $target, string $root, string $runId, CoverageMap $aggregate): void
    {
        if ($this->closed) {
            throw new \LogicException('The per-test coverage store is already closed.');
        }

        $directory = \dirname($target);
        $exists = ErrorTrap::run(static fn(): bool => \is_dir($directory));

        if (!$exists && !ErrorTrap::run(static fn(): bool => \mkdir($directory, 0o777, true), $warning)) {
            throw CoverageError::artifactWriteFailed($target, $warning);
        }

        $temporary = $target . '.tmp-' . (int) \getmypid() . '-' . \bin2hex(\random_bytes(8));
        $stream = ErrorTrap::run(static fn() => \fopen($temporary, 'wb'), $warning);

        if (!\is_resource($stream)) {
            throw CoverageError::artifactWriteFailed($temporary, $warning);
        }

        try {
            try {
                $this->writeLine($stream, [
                    'v' => $this->version(),
                    'type' => 'meta',
                    'root' => $root,
                    'runId' => $runId,
                    'complete' => true,
                    ...($this->branchCoverage ? ['branchCoverage' => true] : []),
                ]);

                foreach ($this->tests as $test) {
                    $this->writeLine($stream, [
                        'v' => $this->version(),
                        'type' => 'test',
                        'test' => $test['ordinal'],
                        'id' => $test['id']->toWire(),
                        'renderedId' => (string) $test['id'],
                        'file' => $test['sourceFile'],
                    ]);
                }

                $source = ErrorTrap::run(fn() => \fopen($this->spool, 'rb'), $warning);

                if (!\is_resource($source)) {
                    throw CoverageError::artifactWriteFailed($this->spool, $warning);
                }

                try {
                    if (ErrorTrap::run(static fn() => \stream_copy_to_stream($source, $stream), $warning) === false) {
                        throw CoverageError::artifactWriteFailed($temporary, $warning);
                    }
                } finally {
                    \fclose($source);
                }

                foreach ($aggregate->files() as $file) {
                    foreach ([[true, $file->coveredLines], [false, $file->uncoveredLines]] as [$covered, $lines]) {
                        foreach (\array_chunk($lines, self::LINES_PER_RECORD) as $chunk) {
                            $this->writeLine($stream, [
                                'v' => $this->version(),
                                'type' => 'source',
                                'file' => $file->file,
                                'covered' => $covered,
                                'lines' => $chunk,
                            ]);
                        }
                    }

                    $mapped = $this->mappedLines[$file->file] ?? [];
                    $unattributed = [];

                    foreach ($file->coveredLines as $line) {
                        if (!isset($mapped[$line])) {
                            $unattributed[] = $line;
                        }
                    }

                    foreach (\array_chunk($unattributed, self::LINES_PER_RECORD) as $chunk) {
                        $this->writeLine($stream, [
                            'v' => $this->version(),
                            'type' => 'unattributed',
                            'file' => $file->file,
                            'lines' => $chunk,
                        ]);
                    }


                    if ($this->branchCoverage) {
                        $this->writeFunctionCoverage($stream, $file);
                    }
                }
            } finally {
                \fclose($stream);
            }
        } catch (\Throwable $error) {
            ErrorTrap::run(static fn(): bool => \unlink($temporary));

            throw $error;
        }

        if (ErrorTrap::run(static fn(): bool => \rename($temporary, $target), $warning) === false) {
            ErrorTrap::run(static fn(): bool => \unlink($temporary));

            throw CoverageError::artifactWriteFailed($target, $warning);
        }

        $this->close();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        ErrorTrap::run(fn(): bool => \unlink($this->spool));
    }

    /**
     * @param non-empty-string $file
     * @param list<positive-int> $lines
     *
     * @return list<positive-int>
     */
    private function withoutIgnored(string $file, array $lines): array
    {
        $ignored = $this->ignoredLines[$file] ??= $this->ignoreScanner->ignoredLines($file);

        return \array_values(\array_filter($lines, static fn(int $line): bool => !isset($ignored[$line])));
    }

    /** @return array{ordinal: non-negative-int, id: TestId, sourceFile: string} */
    private function test(TestId $id): array
    {
        $test = $this->tests[(string) $id] ?? null;

        if ($test === null) {
            throw new \LogicException(\sprintf('Coverage arrived for unknown test "%s".', $id));
        }

        return $test;
    }

    /**
     * @param resource $stream
     * @throws CoverageError
     */
    private function writeFunctionCoverage(mixed $stream, FileCoverage $file): void
    {
        foreach ($file->functions as $function) {
            foreach ($function->branches as $branch) {
                $this->writeLine($stream, [
                    'v' => $this->version(),
                    'type' => 'source-branch',
                    'file' => $file->file,
                    'function' => $function->name,
                    ...$branch->toWire(),
                ]);

                if ($branch->covered && !isset($this->mappedBranches[$file->file][$function->name][$branch->id])) {
                    $this->writeLine($stream, [
                        'v' => $this->version(),
                        'type' => 'unattributed-branch',
                        'file' => $file->file,
                        'function' => $function->name,
                        'branch' => $branch->id,
                    ]);
                }
            }

            foreach ($function->paths as $path) {
                $this->writeLine($stream, [
                    'v' => $this->version(),
                    'type' => 'source-path',
                    'file' => $file->file,
                    'function' => $function->name,
                    ...$path->toWire(),
                ]);

                if ($path->covered && !isset($this->mappedPaths[$file->file][$function->name][$path->identity()])) {
                    $this->writeLine($stream, [
                        'v' => $this->version(),
                        'type' => 'unattributed-path',
                        'file' => $file->file,
                        'function' => $function->name,
                        'branches' => $path->branches,
                    ]);
                }
            }
        }
    }

    private function version(): int
    {
        return $this->branchCoverage ? self::BRANCH_VERSION : self::LINE_VERSION;
    }

    /**
     * @param array<string, mixed> $record
     * @throws CoverageError
     */
    private function append(array $record): void
    {
        $line = \json_encode($record, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES) . "\n";

        if (ErrorTrap::run(fn(): int|false => \file_put_contents($this->spool, $line, \FILE_APPEND), $warning) === false) {
            throw CoverageError::artifactWriteFailed($this->spool, $warning);
        }
    }

    /**
     * @param resource $stream
     * @param array<string, mixed> $record
     * @throws CoverageError
     */
    private function writeLine(mixed $stream, array $record): void
    {
        $remaining = \json_encode($record, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES) . "\n";

        while ($remaining !== '') {
            $written = ErrorTrap::run(static fn() => \fwrite($stream, $remaining), $warning);

            if ($written === false || $written === 0) {
                throw CoverageError::artifactWriteFailed($this->spool, $warning);
            }

            $remaining = \substr($remaining, $written);
        }
    }
}
