<?php

declare(strict_types=1);

namespace Greenlight\Cli\Coverage;

use Greenlight\Coverage\Collection\CoverageCollector;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Coverage\Collection\Driver\PcovDriver;
use Greenlight\Coverage\Collection\Driver\XdebugDriver;
use Greenlight\Coverage\Collection\PathFilter;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Relay\SharedCoverageDirectory;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Owns and merges the coverage resources for one CLI run.
 *
 * finish() adds each source file in the include paths that no test loaded.
 * These files have only uncovered lines. A probe compiles them in a
 * subprocess with the coverage driver of the run. Then finish() removes the
 * files in the exclude paths, also files that a worker or a child process
 * reported.
 *
 * @internal
 */
final class CoverageSession
{
    private ?CoverageCollector $collector = null;

    private ?SharedCoverageDirectory $shared = null;

    private function __construct(
        private readonly ?CoverageSettings $settings = null,
        private readonly ?string $temporaryDirectory = null,
        private readonly ?ExecutableLineProbe $probe = null,
    ) {}

    /**
     * @throws CoverageError
     */
    public static function open(
        ?CoverageSettings $settings,
        bool $collectProcess,
        ?string $temporaryDirectory = null,
        ?ExecutableLineProbe $probe = null,
    ): self {
        $session = new self($settings, $temporaryDirectory, $probe);

        if (!$settings instanceof CoverageSettings) {
            return $session;
        }

        try {
            if ($collectProcess) {
                $collector = CoverageCollector::create($settings);

                if ($collector instanceof CoverageCollector) {
                    $collector->start();
                    $session->collector = $collector;
                }
            }

            $session->shared = SharedCoverageDirectory::open($settings, $temporaryDirectory);
        } catch (\Throwable $failure) {
            $session->close();

            throw $failure;
        }

        return $session;
    }

    /**
     * @throws CoverageError
     */
    public function finish(?CoverageMap $coverage): ?CoverageMap
    {
        if ($this->collector instanceof CoverageCollector) {
            $collector = $this->collector;
            $this->collector = null;
            $collected = $collector->stop();

            if (!$collected->isEmpty()) {
                $coverage = $coverage instanceof CoverageMap ? $coverage->merge($collected) : $collected;
            }
        }

        if ($this->shared instanceof SharedCoverageDirectory) {
            $shared = $this->shared;
            $this->shared = null;
            $dumped = $shared->drain();

            if ($dumped instanceof CoverageMap) {
                $coverage = $coverage instanceof CoverageMap ? $coverage->merge($dumped) : $dumped;
            }
        }

        if ($coverage instanceof CoverageMap && $this->settings instanceof CoverageSettings && $this->settings->includePaths !== []) {
            $coverage = new UnloadedFileCoverage($this->probe ?? $this->defaultProbe($this->settings))
                ->complete($coverage, $this->settings->includePaths, $this->settings->excludePaths);
        }

        if ($coverage instanceof CoverageMap && $this->settings instanceof CoverageSettings && $this->settings->excludePaths !== []) {
            $coverage = $this->withoutExcluded($coverage, new PathFilter([], $this->settings->excludePaths));
        }

        return $coverage;
    }

    private function withoutExcluded(CoverageMap $coverage, PathFilter $filter): CoverageMap
    {
        $files = [];

        foreach ($coverage->files() as $path => $file) {
            $real = ErrorTrap::run(static fn() => \realpath($path));

            if ($filter->accepts($path) && (!\is_string($real) || $filter->accepts($real))) {
                $files[] = $file;
            }
        }

        return new CoverageMap($files);
    }

    /**
     * @throws CoverageError
     */
    private function defaultProbe(CoverageSettings $settings): ExecutableLineProbe
    {
        $driver = match (true) {
            $settings->driver === 'pcov', $settings->driver === 'xdebug' => $settings->driver,
            PcovDriver::isAvailable() => 'pcov',
            XdebugDriver::isAvailable() => 'xdebug',
            default => throw CoverageError::lineProbeFailed('No coverage driver is available in the main process.'),
        };
        $directory = $this->temporaryDirectory ?? \sys_get_temp_dir();

        return new CompiledLineProbe($driver, $directory === '' ? '/tmp' : $directory);
    }

    public function close(): void
    {
        if ($this->collector instanceof CoverageCollector) {
            $collector = $this->collector;
            $this->collector = null;

            try {
                $collector->stop();
            } catch (\Throwable) {
                // Preserve the run failure if cleanup also fails.
            }
        }

        if ($this->shared instanceof SharedCoverageDirectory) {
            $shared = $this->shared;
            $this->shared = null;

            try {
                $shared->drain();
            } catch (\Throwable) {
                // Preserve the run failure if cleanup also fails.
            }
        }
    }
}
