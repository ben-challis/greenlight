<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Coverage\Driver\CoverageDriver;
use Greenlight\Coverage\RawCoverage;

final class RecordingFakeDriver implements CoverageDriver
{
    private static bool $started = false;

    #[\Override]
    public static function isAvailable(): bool
    {
        return true;
    }

    #[\Override]
    public function start(): void
    {
        self::$started = true;
    }

    #[\Override]
    public function stop(): RawCoverage
    {
        self::$started = false;

        return new RawCoverage([
            '/project/src/Included.php' => [
                10 => 1,
                11 => -1,
                12 => -2,
            ],
            '/project/tests/Excluded.php' => [
                20 => 1,
            ],
        ]);
    }

    public static function started(): bool
    {
        return self::$started;
    }
}
