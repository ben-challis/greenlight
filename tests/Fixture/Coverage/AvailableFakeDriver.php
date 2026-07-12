<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Coverage\Driver\CoverageDriver;
use Greenlight\Coverage\RawCoverage;

/**
 * A CoverageDriver candidate that always reports itself available, for
 * deterministically exercising DriverSelector's selected branch.
 */
final class AvailableFakeDriver implements CoverageDriver
{
    #[\Override]
    public static function isAvailable(): bool
    {
        return true;
    }

    #[\Override]
    public function start(): void {}

    #[\Override]
    public function stop(): RawCoverage
    {
        return RawCoverage::none();
    }
}
