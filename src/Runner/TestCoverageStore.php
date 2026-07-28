<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Test\TestId;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Ignore\IgnoreScanner;
use Greenlight\Discovery\ExecutionPlan;

/**
 * Spools the many-to-many test coverage relation while keeping memory bounded
 * by the execution plan and the union of attributed source lines.
 *
 * @internal
 */
final class TestCoverageStore
{
    private const int VERSION = 1;

    private readonly string $spool;

    /**
     * @var array<string, array{ordinal: non-negative-int, id: TestId, sourceFile: string}>
     */
    private array $tests = [];

    /**
     * @var array<string, array<positive-int, true>>
     */
    private array $mappedLines = [];

    /**
     * @var array<string, array<int, true>>
     */
    private array $ignoredLines = [];

    private bool $closed = false;

    /** @throws CoverageError */
    private function __construct(private readonly IgnoreScanner $ignoreScanner)
    {
        $this->spool = \rtrim(\sys_get_temp_dir(), '/') . '/greenlight-test-coverage-' . \bin2hex(\random_bytes(8)) . '.jsonl';

        if (ErrorTrap::run(fn(): int|false => \file_put_contents($this->spool, ''), $warning) === false) {
            throw CoverageError::artifactWriteFailed($this->spool, $warning);
        }
    }

    /** @throws CoverageError */
    public static function open(): self
    {
        return new self(new IgnoreScanner());
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

            foreach (\array_chunk($lines, 50_000) as $chunk) {
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

        ErrorTrap::run(static fn(): bool => \mkdir(\dirname($target), 0o777, true));
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
                    if (\stream_copy_to_stream($source, $stream) === false) {
                        throw CoverageError::artifactWriteFailed($temporary, 'could not copy the coverage spool');
                    }
                } finally {
                    \fclose($source);
                }

                foreach ($aggregate->files() as $file) {
                    foreach ([
                        [true, $file->coveredLines],
                        [false, $file->uncoveredLines],
                    ] as [$covered, $lines]) {
                        foreach (\array_chunk($lines, 50_000) as $chunk) {
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

                    foreach (\array_chunk($unattributed, 50_000) as $chunk) {
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
        $line = \json_encode($record, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES) . "\n";

        if (\fwrite($stream, $line) === false) {
            throw CoverageError::artifactWriteFailed($this->spool, 'could not write the artifact stream');
        }
    }
}
