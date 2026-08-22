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
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Coverage\RecordingFakeDriver;
use Greenlight\Tests\Fixture\Coverage\UnavailableFakeDriver;

final class CoverageCollectorTest
{
    #[Test]
    public function startsStopsAndFiltersTheSelectedDriver(): void
    {
        $collector = CoverageCollector::create(
            new CoverageSettings(['/project/src']),
            selector: new DriverSelector([RecordingFakeDriver::class]),
        );

        Expect::that($collector)
            ->because('The available driver MUST create a coverage collector.')
            ->toBeInstanceOf(CoverageCollector::class);

        $collector->start();

        Expect::that(RecordingFakeDriver::started())
            ->because('the collector starts the selected driver')
            ->toBeTrue();

        $files = $collector->stop()->files();

        Expect::that(RecordingFakeDriver::started())
            ->because('the collector stops the selected driver')
            ->toBeFalse();
        Expect::that(\array_keys($files))
            ->because('the collector filters raw coverage to the included paths')
            ->toBe(['/project/src/Included.php']);
        Expect::that($files['/project/src/Included.php']->coveredLines)->toBe([10]);
        Expect::that($files['/project/src/Included.php']->uncoveredLines)->toBe([11]);
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

        Expect::that($collector)
            ->because('an unavailable driver does not create a collector')
            ->toBeNull();
        Expect::that($reason)->toBe(
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

        Expect::that($collector)
            ->because('The available driver MUST create a coverage collector.')
            ->toBeInstanceOf(CoverageCollector::class);

        $collector->start();
        $files = $collector->stop()->files();

        Expect::that(\array_keys($files))
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

            Expect::that($reason)
                ->because(\sprintf('Unavailable %s coverage MUST report a reason.', $setting))
                ->toBeString();

            Expect::that($collector)
                ->because($setting . ' selects only its configured coverage driver')
                ->toBeNull();
            Expect::that($reason)->toContain('Greenlight tried ' . $expectedName . '.');
            Expect::that(\str_contains($reason, $otherName))->toBeFalse();

            return;
        }

        Expect::that($collector)
            ->because(\sprintf('Available %s coverage MUST create a collector.', $setting))
            ->toBeInstanceOf(CoverageCollector::class);

        $driver = new \ReflectionProperty(CoverageCollector::class, 'driver')->getValue($collector);

        Expect::that($driver)
            ->because($setting . ' selects only its configured coverage driver')
            ->toBeInstanceOf($expected);
    }
}
