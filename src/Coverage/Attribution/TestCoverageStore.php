<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Attribution;

use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Ignore\IgnoreScanner;
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
    private const int VERSION = 1;
    private const int LINES_PER_RECORD = 50_000;

    private readonly string $spool;

    /** @var array<string, array{ordinal: non-negative-int, id: TestId, sourceFile: string}> */
    private array $tests = [];

    /** @var array<non-empty-string, array<positive-int, true>> */
    private array $mappedLines = [];

    /** @var array<non-empty-string, array<int, true>> */
    private array $ignoredLines = [];

    private bool $closed = false;

    /** @throws CoverageError */
    private function __construct(string $temporaryDirectory, private readonly IgnoreScanner $ignoreScanner)
    {
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
    public static function open(?string $temporaryDirectory = null): self
    {
        return new self($temporaryDirectory ?? \sys_get_temp_dir(), new IgnoreScanner());
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
                    'v' => self::VERSION,
                    'type' => 'coverage',
                    'test' => $test['ordinal'],
                    'file' => $file->file,
                    'lines' => $chunk,
                ]);
            }
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
                    'v' => self::VERSION,
                    'type' => 'meta',
                    'root' => $root,
                    'runId' => $runId,
                    'complete' => true,
                ]);

                foreach ($this->tests as $test) {
                    $this->writeLine($stream, [
                        'v' => self::VERSION,
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
                                'v' => self::VERSION,
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
                            'v' => self::VERSION,
                            'type' => 'unattributed',
                            'file' => $file->file,
                            'lines' => $chunk,
                        ]);
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
