<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection;

use Greenlight\Coverage\Collection\Driver\CoverageDriver;
use Greenlight\Coverage\Collection\Driver\DriverSelector;
use Greenlight\Coverage\Collection\Driver\PcovDriver;
use Greenlight\Coverage\Collection\Driver\XdebugDriver;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;

/**
 * Collects coverage during one part of test execution.
 *
 * If no coverage driver is available, create() sends the reason to the optional
 * callback and returns null. The run continues without coverage.
 *
 * @internal
 */
final readonly class CoverageCollector
{
    private function __construct(
        private CoverageDriver $driver,
        private PathFilter $filter,
    ) {}

    /**
     * @param \Closure(string): void|null $unavailable receives the reason when no driver can collect
     * @param DriverSelector|null $selector The selector to use. Null selects the configured built-in drivers.
     * @throws CoverageError
     */
    public static function create(
        CoverageSettings $settings,
        ?\Closure $unavailable = null,
        ?DriverSelector $selector = null,
    ): ?self {
        $candidates = match (true) {
            $settings->branchCoverage => [XdebugDriver::class],
            $settings->driver === 'pcov' => [PcovDriver::class],
            $settings->driver === 'xdebug' => [XdebugDriver::class],
            default => [PcovDriver::class, XdebugDriver::class],
        };

        $selection = ($selector ?? new DriverSelector($candidates))->select($settings->branchCoverage);
        $driver = $selection->driver;

        if (!$driver instanceof CoverageDriver) {
            if ($unavailable instanceof \Closure) {
                $unavailable($selection->reason ?? 'no coverage driver is available');
            }

            return null;
        }

        return new self(
            $driver,
            $settings->includePaths === [] ? PathFilter::all() : new PathFilter($settings->includePaths),
        );
    }

    public function start(): void
    {
        $this->driver->start();
    }

    public function stop(): CoverageMap
    {
        return $this->driver->stop()->toMap($this->filter);
    }
}
