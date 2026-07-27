<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Driver\PcovDriver;
use Greenlight\Expect\Expect;

final class PcovDriverTest
{
    #[Test]
    public function reportsInvalidCollectionStateExactly(): void
    {
        $driver = new \ReflectionClass(PcovDriver::class)->newInstanceWithoutConstructor();

        Expect::that(static fn(): mixed => $driver->stop())
            ->toThrow(
                \LogicException::class,
                message: 'The pcov collection window is not open. Call start() before stop().',
            );

        $collecting = new \ReflectionProperty(PcovDriver::class, 'collecting');
        $collecting->setValue($driver, true);

        Expect::that(static fn() => $driver->start())
            ->toThrow(
                \LogicException::class,
                message: 'The pcov collection window is already open. Call stop() before start().',
            );
    }
}
