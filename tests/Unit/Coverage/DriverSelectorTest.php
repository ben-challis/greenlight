<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Driver\CoverageDriver;
use Greenlight\Coverage\Driver\DriverSelector;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Coverage\AvailableFakeDriver;
use Greenlight\Tests\Fixture\Coverage\UnavailableFakeDriver;

final class DriverSelectorTest
{
    #[Test]
    public function emptyCandidateListYieldsNoDriverAndAReason(): void
    {
        $selection = new DriverSelector([])->select();

        Expect::that($selection->driver)->toBeNull()
            ->and($selection->reason)->toBe('No coverage driver is available: no drivers are configured.');
    }

    #[Test]
    public function anAvailableCandidateIsSelectedWithNoReason(): void
    {
        $selection = new DriverSelector([UnavailableFakeDriver::class, AvailableFakeDriver::class])->select();

        Expect::that($selection->driver)->toBeInstanceOf(AvailableFakeDriver::class)
            ->and($selection->reason)->toBeNull();
    }

    #[Test]
    public function noAvailableCandidateYieldsNoDriverAndANamedReason(): void
    {
        $selection = new DriverSelector([UnavailableFakeDriver::class])->select();

        Expect::that($selection->driver)->toBeNull()
            ->and($selection->reason)->toBe('No coverage driver is available: tried UnavailableFakeDriver. Install pcov, or enable xdebug with "coverage" in xdebug.mode or the XDEBUG_MODE environment variable.');
    }

    #[Test]
    public function defaultSelectionYieldsExactlyADriverOrAReason(): void
    {
        // Installed extensions determine the actual candidate. The tests with
        // fake coverage drivers check both paths with deterministic results.
        $selection = new DriverSelector()->select();

        if ($selection->driver instanceof CoverageDriver) {
            Expect::that($selection->reason)->toBeNull();
        } else {
            Expect::that($selection->reason)->not()->toBeNull();
        }
    }
}
