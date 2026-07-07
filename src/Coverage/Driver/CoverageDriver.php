<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Driver;

use Greenlight\Coverage\RawCoverage;

/**
 * A source of raw line coverage.
 *
 * Drivers open a collection window with start() and close it with stop(),
 * typically once per test or per plan slice.
 *
 * Implementations must be constructible without arguments so a selector can
 * instantiate them from class names, and must only be constructed when
 * isAvailable() returns true.
 *
 * @internal
 */
interface CoverageDriver
{
    public static function isAvailable(): bool;

    public function start(): void;

    public function stop(): RawCoverage;
}
