<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Driver\XdebugDriver;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Coverage\StartFailingXdebugRuntime;

final readonly class XdebugDriverStartFailureTest
{
    #[Test]
    public function aStartFailureLeavesTheCollectionWindowClosed(): void
    {
        $runtime = new StartFailingXdebugRuntime();
        $driver = new XdebugDriver($runtime, flags: 3);

        Expect::that(static function () use ($driver): void {
            $driver->start();
        })
            ->because('an Xdebug start failure MUST remain the reported failure')
            ->toThrow(
                \RuntimeException::class,
                message: 'Xdebug start failed.',
            )
            ->and($runtime->calls)
            ->because('a failed Xdebug start MUST NOT collect or stop the runtime')
            ->toBe(['start']);

        Expect::that(static fn(): mixed => $driver->stop())
            ->because('a failed Xdebug start MUST leave the collection window closed')
            ->toThrow(
                \LogicException::class,
                message: 'The Xdebug collection window is not open. Call start() before stop().',
            );
    }
}
