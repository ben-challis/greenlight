<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Coverage\Driver\CoverageDriver;
use Greenlight\Coverage\RawCoverage;

/**
 * A CoverageDriver candidate that always reports itself unavailable, for
 * deterministically exercising DriverSelector's no-driver branch.
 */
final class UnavailableFakeDriver implements CoverageDriver
{
    #[\Override]
    public static function isAvailable(): bool
    {
        return false;
    }

    #[\Override]
    public function start(): void {}

    #[\Override]
    public function stop(): RawCoverage
    {
        return RawCoverage::none();
    }
}
