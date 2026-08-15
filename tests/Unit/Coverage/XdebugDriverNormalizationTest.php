<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\Driver\XdebugDriver;
use Greenlight\Coverage\Driver\XdebugRuntime;
use Greenlight\Expect\Expect;

final class XdebugDriverNormalizationTest
{
    #[Test]
    #[Isolated]
    public function malformedCoverageEntriesAreDiscarded(): void
    {
        if (!\defined('XDEBUG_CC_UNUSED')) {
            \define('XDEBUG_CC_UNUSED', 1);
        }

        if (!\defined('XDEBUG_CC_DEAD_CODE')) {
            \define('XDEBUG_CC_DEAD_CODE', 2);
        }

        $runtime = new class implements XdebugRuntime {
            #[\Override]
            public function start(int $flags): void {}

            /**
             * @return array<mixed>
             */
            #[\Override]
            public function collect(): array
            {
                return [
                    '/valid.php' => [
                        10 => 1,
                        11 => -1,
                        'line' => 1,
                        12 => 'covered',
                    ],
                    7 => [20 => 1],
                    '/invalid.php' => 'not line coverage',
                ];
            }

            #[\Override]
            public function stop(): void {}
        };

        $driver = new XdebugDriver($runtime);
        $driver->start();
        $coverage = $driver->stop();

        Expect::that($coverage->lines)
            ->because('Xdebug coverage MUST keep only integer statuses keyed by integer lines')
            ->toBe([
                '/valid.php' => [
                    10 => 1,
                    11 => -1,
                ],
            ]);
    }
}
