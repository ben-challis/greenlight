<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Collection;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Collection\CoverageCollector;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Coverage\Collection\Driver\CoverageDriver;
use Greenlight\Coverage\Collection\Driver\DriverSelector;
use Greenlight\Coverage\Collection\Driver\PcovDriver;
use Greenlight\Coverage\Collection\Driver\XdebugDriver;
use Greenlight\Tests\Fixture\Coverage\RecordingFakeDriver;
use Greenlight\Tests\Fixture\Coverage\UnavailableFakeDriver;

use function Greenlight\expect;

final class CoverageCollectorTest
{
    #[Test]
    public function startsStopsAndFiltersTheSelectedDriver(): void
    {
        $collector = CoverageCollector::create(
            new CoverageSettings(['/project/src']),
            selector: new DriverSelector([RecordingFakeDriver::class]),
        );

        expect($collector)
            ->because('The available driver MUST create a coverage collector.')
            ->toBeInstanceOf(CoverageCollector::class);

        $collector->start();

        expect(RecordingFakeDriver::started())
            ->because('the collector starts the selected driver')
            ->toBeTrue();

        $files = $collector->stop()->files();

        expect(RecordingFakeDriver::started())
            ->because('the collector stops the selected driver')
            ->toBeFalse();
        expect(\array_keys($files))
            ->because('the collector filters raw coverage to the included paths')
            ->toBe(['/project/src/Included.php']);
        expect($files['/project/src/Included.php']->coveredLines)->toBe([10]);
        expect($files['/project/src/Included.php']->uncoveredLines)->toBe([11]);
    }

    #[Test]
    public function unavailableSelectionReturnsNullAndSendsTheReason(): void
    {
        $reason = null;
        $collector = CoverageCollector::create(
            new CoverageSettings([]),
            static function (string $message) use (&$reason): void {
                $reason = $message;
            },
            new DriverSelector([UnavailableFakeDriver::class]),
        );

        expect($collector)
            ->because('an unavailable driver does not create a collector')
            ->toBeNull();
        expect($reason)->toBe(
            'No coverage driver is available. Greenlight tried UnavailableFakeDriver. Install pcov or enable Xdebug coverage mode. '
                . 'Set xdebug.mode to "coverage", or set the XDEBUG_MODE environment variable.',
        );
    }

    #[Test]
    public function emptyIncludePathsKeepCoverageFromEveryFile(): void
    {
        $collector = CoverageCollector::create(
            new CoverageSettings([]),
            selector: new DriverSelector([RecordingFakeDriver::class]),
        );

        expect($collector)
            ->because('The available driver MUST create a coverage collector.')
            ->toBeInstanceOf(CoverageCollector::class);

        $collector->start();
        $files = $collector->stop()->files();

        expect(\array_keys($files))
            ->because('empty coverage include paths MUST retain every collected file')
            ->toBe([
                '/project/src/Included.php',
                '/project/tests/Excluded.php',
            ]);
    }

    #[Test]
    public function explicitSettingsSelectOnlyTheRequestedDriver(): void
    {
        $this->expectExplicitDriver('pcov', PcovDriver::class, XdebugDriver::class);
        $this->expectExplicitDriver('xdebug', XdebugDriver::class, PcovDriver::class);
    }

    /**
     * @param 'pcov'|'xdebug' $setting
     * @param class-string<CoverageDriver> $expected
     * @param class-string<CoverageDriver> $other
     */
    private function expectExplicitDriver(string $setting, string $expected, string $other): void
    {
        $reason = null;
        $collector = CoverageCollector::create(
            new CoverageSettings([], $setting),
            static function (string $message) use (&$reason): void {
                $reason = $message;
            },
        );

        if (!$expected::isAvailable()) {
            $expectedName = new \ReflectionClass($expected)->getShortName();
            $otherName = new \ReflectionClass($other)->getShortName();

            expect($reason)
                ->because(\sprintf('Unavailable %s coverage MUST report a reason.', $setting))
                ->toBeString();

            expect($collector)
                ->because($setting . ' selects only its configured coverage driver')
                ->toBeNull();
            expect($reason)->toContain('Greenlight tried ' . $expectedName . '.');
            expect(\str_contains($reason, $otherName))->toBeFalse();

            return;
        }

        expect($collector)
            ->because(\sprintf('Available %s coverage MUST create a collector.', $setting))
            ->toBeInstanceOf(CoverageCollector::class);

        $driver = new \ReflectionProperty(CoverageCollector::class, 'driver')->getValue($collector);

        expect($driver)
            ->because($setting . ' selects only its configured coverage driver')
            ->toBeInstanceOf($expected);
    }
}
