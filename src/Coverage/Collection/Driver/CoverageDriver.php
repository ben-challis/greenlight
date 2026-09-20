<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection\Driver;

use Greenlight\Coverage\Collection\RawCoverage;

/**
 * Collects raw line coverage.
 *
 * A selector calls isAvailable() before construction. It constructs a driver
 * only when this method returns true. Give each implementation a constructor
 * with no required arguments so a selector can create it from its class name.
 *
 * @internal
 */
interface CoverageDriver
{
    public static function isAvailable(): bool;

    /**
     * @throws \LogicException when a collection window is already open
     */
    public function start(): void;

    /**
     * @throws \LogicException when no collection window is open
     */
    public function stop(): RawCoverage;
}
