<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Collection;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Collection\Driver\PcovDriver;
use Greenlight\Coverage\CoverageError;
use Greenlight\Tests\Fixture\Coverage\FakePcovDriverRuntime;

use function Greenlight\expect;

final class PcovDriverTest
{
    #[Test]
    public function availabilityRequiresAnEnabledExtensionAndMissingDriverIsActionable(): void
    {
        $available = \extension_loaded('pcov') && \filter_var(\ini_get('pcov.enabled'), \FILTER_VALIDATE_BOOL);

        expect(PcovDriver::isAvailable())
            ->because('PCOV availability requires its execution hooks to be enabled')
            ->toBe($available);

        if ($available) {
            expect(new PcovDriver())
                ->because('an available PCOV extension permits driver construction')
                ->toBeInstanceOf(PcovDriver::class);

            return;
        }

        expect()->calling(static fn(): PcovDriver => new PcovDriver())
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

        expect()->calling(static fn(): mixed => $driver->stop())
            ->toThrow(
                \LogicException::class,
                message: 'The pcov collection window is not open. Call start() before stop().',
            );

        $driver->start();

        expect()->calling(static fn() => $driver->start())
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

        expect($coverage->lines)
            ->because('PCOV collection returns the extension line statuses')
            ->toBe([
                '/src/Example.php' => [
                    10 => 1,
                    11 => -1,
                ],
            ]);
        expect($runtime->calls)
            ->because('PCOV collection MUST stop and clear extension state after reading it')
            ->toBe(['start', 'collect', 'stop', 'clear']);
    }
}
