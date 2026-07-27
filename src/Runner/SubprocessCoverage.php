<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Core\ErrorTrap;
use Greenlight\Coverage\Export\JsonExporter;

/**
 * GREENLIGHT_COVERAGE_DIR and GREENLIGHT_COVERAGE_INCLUDE send coverage from
 * a child CLI process to its parent run. A child process inherits these
 * variables. A missing coverage driver does not fail the run. Greenlight does
 * not write an empty coverage map.
 *
 * Worker processes do not write coverage files. They send coverage through
 * the worker protocol. A second collection period closes the inherited process
 * period too early.
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
