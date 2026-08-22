<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Collection;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Collection\Driver\PcovDriver;
use Greenlight\Coverage\CoverageError;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Coverage\FakePcovDriverRuntime;

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
        $driver = new PcovDriver(new FakePcovDriverRuntime());

        Expect::that(static fn(): mixed => $driver->stop())
            ->toThrow(
                \LogicException::class,
                message: 'The pcov collection window is not open. Call start() before stop().',
            );

        $driver->start();

        Expect::that(static fn() => $driver->start())
            ->toThrow(
                \LogicException::class,
                message: 'The pcov collection window is already open. Call stop() before start().',
            );
    }

    #[Test]
    public function collectionLifecycleReturnsExtensionPayloadAndClearsState(): void
    {
        $runtime = new FakePcovDriverRuntime();
        $driver = new PcovDriver($runtime);

        $driver->start();
        $coverage = $driver->stop();

        Expect::that($coverage->lines)
            ->because('PCOV collection returns the extension line statuses')
            ->toBe([
                '/src/Example.php' => [
                    10 => 1,
                    11 => -1,
                ],
            ]);
        Expect::that($runtime->calls)
            ->because('PCOV collection MUST stop and clear extension state after reading it')
            ->toBe(['start', 'collect', 'stop', 'clear']);
    }
}
