<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Driver\CoverageDriver;
use Greenlight\Coverage\Driver\DriverSelector;
use Greenlight\Expect\Expect;

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
    public function defaultSelectionYieldsExactlyADriverOrAReason(): void
    {
        $selection = new DriverSelector()->select();

        if (!$selection->driver instanceof CoverageDriver) {
            Expect::that($selection->reason)->toContain('No coverage driver is available: tried PcovDriver, XdebugDriver.')
                ->and($selection->reason)->toContain('Install pcov');

            return;
        }

        Expect::that($selection->reason)->toBeNull();
    }
}
