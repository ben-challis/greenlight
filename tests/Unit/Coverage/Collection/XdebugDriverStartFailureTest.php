<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Collection;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Collection\Driver\XdebugDriver;
use Greenlight\Tests\Fixture\Coverage\StartFailingXdebugRuntime;

use function Greenlight\expect;

final readonly class XdebugDriverStartFailureTest
{
    #[Test]
    public function aStartFailureLeavesTheCollectionWindowClosed(): void
    {
        $failure = new \RuntimeException('Xdebug start failed.');
        $runtime = new StartFailingXdebugRuntime($failure);
        $driver = new XdebugDriver($runtime, flags: 3);

        expect()->calling(static function () use ($driver): void {
            $driver->start();
        })
            ->because('an Xdebug start failure MUST remain the reported failure')
            ->toThrow($failure);

        expect($runtime->calls)
            ->because('a failed Xdebug start MUST NOT collect or stop the runtime')
            ->toBe(['start']);

        expect()->calling(static fn(): mixed => $driver->stop())
            ->because('a failed Xdebug start MUST leave the collection window closed')
            ->toThrow(
                \LogicException::class,
                message: 'The Xdebug collection window is not open. Call start() before stop().',
            );
    }
}
