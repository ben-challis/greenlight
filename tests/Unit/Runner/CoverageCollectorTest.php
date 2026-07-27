<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Driver\DriverSelector;
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
}
