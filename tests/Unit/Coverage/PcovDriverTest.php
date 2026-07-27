<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\Driver\PcovDriver;
use Greenlight\Expect\Expect;

final class PcovDriverTest
{
    #[Test]
    public function availabilityMatchesTheExtensionAndMissingDriverIsActionable(): void
    {
        $available = \extension_loaded('pcov');

        Expect::that(PcovDriver::isAvailable())
            ->because('PCOV availability matches the loaded extension')
            ->toBe($available);

        if ($available) {
            Expect::that(new PcovDriver())
                ->because('an available PCOV extension permits driver construction')
                ->toBeInstanceOf(PcovDriver::class);

            return;
        }

        Expect::that(static fn(): PcovDriver => new PcovDriver())
            ->because('a missing PCOV extension gives exact installation guidance')
            ->toThrow(
                CoverageError::class,
                message: 'Coverage driver "pcov" is not available. Install and enable the pcov extension.',
            );
    }

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
