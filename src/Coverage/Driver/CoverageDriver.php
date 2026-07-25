<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Driver;

use Greenlight\Coverage\RawCoverage;

/**
 * Implementations must be constructible without arguments so a selector can
 * instantiate them from class names, and must only be constructed when
 * isAvailable() returns true.
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
