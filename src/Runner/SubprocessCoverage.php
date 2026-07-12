<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Core\ErrorTrap;
use Greenlight\Coverage\Export\JsonExporter;

/**
 * Reports a spawned CLI process's own coverage back to the run that spawned it.
 *
 * A coverage-enabled run exports GREENLIGHT_COVERAGE_DIR and
 * GREENLIGHT_COVERAGE_INCLUDE to the processes it starts. Any bin/greenlight
 * process that inherits them, typically one spawned by an acceptance test
 * driving the real CLI, collects its own coverage and drops a JSON export
 * into the shared directory, where the spawning run folds it into the final
 * CoverageMap. Without this relay, orchestrator-side code exercised only
 * through spawned processes reports no coverage at all.
 *
 * begin() opens the collection window when the variables are present and a
 * driver is available; like worker collection it fails soft, so a missing
 * driver never fails the spawned command. write() closes the window and
 * writes the dump under a unique name; empty maps are not written.
 * requested() reports whether the variables are present, which the spawning
 * side uses to avoid opening a second driver window in a process that is
 * already dumping.
 *
 * Worker processes never dump; their coverage travels over the worker
 * protocol. A process that also opens an inner collection window, such as a
 * workers=1 run with coverage enabled, closes the shared driver state early
 * and truncates its dump.
 *
 * @internal
 */
final readonly class SubprocessCoverage
{
    public const string DIRECTORY_ENV = 'GREENLIGHT_COVERAGE_DIR';
    public const string INCLUDE_ENV = 'GREENLIGHT_COVERAGE_INCLUDE';

    private function __construct(
        private CoverageCollector $collector,
        private string $directory,
    ) {}

    public static function requested(): bool
    {
        $directory = \getenv(self::DIRECTORY_ENV);

        return \is_string($directory) && $directory !== '';
    }

    public static function begin(): ?self
    {
        $directory = \getenv(self::DIRECTORY_ENV);

        if (!\is_string($directory) || $directory === '') {
            return null;
        }

        $include = \getenv(self::INCLUDE_ENV);
        $paths = [];

        if (\is_string($include)) {
            foreach (\explode(\PATH_SEPARATOR, $include) as $path) {
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        }

        $collector = CoverageCollector::create(new CoverageSettings($paths));

        if (!$collector instanceof CoverageCollector) {
            return null;
        }

        $collector->start();

        return new self($collector, $directory);
    }

    public function write(): void
    {
        $map = $this->collector->stop();

        if ($map->isEmpty()) {
            return;
        }

        $pid = \getmypid();
        $export = new JsonExporter()->export($map);
        $file = \sprintf(
            '%s/%d-%s.json',
            \rtrim($this->directory, '/'),
            $pid === false ? 0 : $pid,
            \bin2hex(\random_bytes(4)),
        );

        ErrorTrap::run(static fn(): int|false => \file_put_contents($file, \reset($export)));
    }
}
