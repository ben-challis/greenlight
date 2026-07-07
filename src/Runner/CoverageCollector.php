<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Driver\CoverageDriver;
use Greenlight\Coverage\Driver\DriverSelector;
use Greenlight\Coverage\Driver\PcovDriver;
use Greenlight\Coverage\Driver\XdebugDriver;
use Greenlight\Coverage\PathFilter;

/**
 * One collection window around a slice of test execution. Creation fails
 * soft: when no driver is available the run proceeds uncovered and the
 * reason is reported, never fatal.
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
     */
    public static function create(CoverageSettings $settings, ?\Closure $unavailable = null): ?self
    {
        $candidates = match ($settings->driver) {
            'pcov' => [PcovDriver::class],
            'xdebug' => [XdebugDriver::class],
            default => [PcovDriver::class, XdebugDriver::class],
        };

        $selection = new DriverSelector($candidates)->select();
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
        return CoverageMap::fromRaw($this->driver->stop(), $this->filter);
    }
}
