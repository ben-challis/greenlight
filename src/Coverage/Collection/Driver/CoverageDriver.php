<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection\Driver;

use Greenlight\Coverage\Collection\RawCoverage;

/**
 * isAvailable() MUST return true before a selector constructs an
 * implementation. Each implementation MUST have a constructor with no
 * arguments. Thus, a selector can create it from its class name.
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
