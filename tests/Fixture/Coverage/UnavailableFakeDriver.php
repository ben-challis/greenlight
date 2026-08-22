<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Coverage\Collection\Driver\CoverageDriver;
use Greenlight\Coverage\Collection\RawCoverage;
use Greenlight\Doubles\Fake;

final class UnavailableFakeDriver implements CoverageDriver, Fake
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
