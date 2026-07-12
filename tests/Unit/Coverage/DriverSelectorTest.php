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
        // The real pcov/Xdebug candidates depend on the running machine's
        // extensions, so this only proves the two outcomes are mutually
        // exclusive; anAvailableCandidateIsSelected and
        // noAvailableCandidateYieldsNoDriverAndANamedReason above cover both
        // branches deterministically with fakes.
        $selection = new DriverSelector()->select();

        Expect::that(!$selection->driver instanceof CoverageDriver)->not()->toBe($selection->reason === null);
    }
}
