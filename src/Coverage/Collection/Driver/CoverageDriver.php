<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection\Driver;

use Greenlight\Coverage\Collection\RawCoverage;

/**
 * Collects raw coverage through one installed extension.
 *
 * Call isAvailable() before construction. Construct the driver only if it
 * returns true. Give each implementation a constructor with no required
 * arguments so that a selector can create it from its class name.
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
