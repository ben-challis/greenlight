<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Driver\XdebugDriver;
use Greenlight\Expect\Expect;

final class XdebugDriverNormalizationTest
{
    #[Test]
    public function malformedCoverageEntriesAreDiscarded(): void
    {
        $driver = new \ReflectionClass(XdebugDriver::class)->newInstanceWithoutConstructor();
        $normalize = new \ReflectionMethod(XdebugDriver::class, 'normalize');

        $normalized = $normalize->invoke($driver, [
            '/valid.php' => [
                10 => 1,
                11 => -1,
                'line' => 1,
                12 => 'covered',
            ],
            7 => [20 => 1],
            '/invalid.php' => 'not line coverage',
        ]);

        Expect::that($normalized)
            ->because('Xdebug coverage MUST keep only integer statuses keyed by integer lines')
            ->toBe([
                '/valid.php' => [
                    10 => 1,
                    11 => -1,
                ],
            ]);
    }
}
