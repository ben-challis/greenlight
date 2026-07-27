<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Driver\CoverageDriver;
use Greenlight\Coverage\Driver\DriverSelector;
use Greenlight\Coverage\Driver\PcovDriver;
use Greenlight\Coverage\Driver\XdebugDriver;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\CoverageCollector;
use Greenlight\Runner\CoverageSettings;
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

        if (!$collector instanceof CoverageCollector) {
            Fail::because('Expected the available driver to create a coverage collector.');
        }

        $collector->start();

        Expect::that(RecordingFakeDriver::started())
            ->because('the collector starts the selected driver')
            ->toBeTrue();

        $files = $collector->stop()->files();

        Expect::that(RecordingFakeDriver::started())
            ->because('the collector stops the selected driver')
            ->toBeFalse()
            ->and(\array_keys($files))
            ->because('the collector filters raw coverage to the included paths')
            ->toBe(['/project/src/Included.php'])
            ->and($files['/project/src/Included.php']->coveredLines)->toBe([10])
            ->and($files['/project/src/Included.php']->uncoveredLines)->toBe([11]);
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
            ->toBeNull()
            ->and($reason)->toBe(
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

        if (!$collector instanceof CoverageCollector) {
            Fail::because('Expected the available driver to create a coverage collector.');
        }

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

            if (!\is_string($reason)) {
                Fail::because(\sprintf('Expected unavailable %s coverage to report a reason.', $setting));
            }

            Expect::that($collector)
                ->because($setting . ' selects only its configured coverage driver')
                ->toBeNull()
                ->and($reason)->toContain('Greenlight tried ' . $expectedName . '.')
                ->and(\str_contains($reason, $otherName))->toBeFalse();

            return;
        }

        if (!$collector instanceof CoverageCollector) {
            Fail::because(\sprintf('Expected available %s coverage to create a collector.', $setting));
        }

        $driver = new \ReflectionProperty(CoverageCollector::class, 'driver')->getValue($collector);

        Expect::that($driver)
            ->because($setting . ' selects only its configured coverage driver')
            ->toBeInstanceOf($expected);
    }
}
