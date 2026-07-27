<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\WorkerProfile;

final class WorkerProfileTest
{
    #[Test]
    public function incompleteTimingDataDoesNotInventMetrics(): void
    {
        $profile = new WorkerProfile();

        Expect::that($profile->bootLatency())
            ->because('incomplete timing data does not invent metrics')
            ->toBeNull()
            ->and($profile->window())
            ->toBe(0.0)
            ->and($profile->utilizationPercent())
            ->toBeNull();
    }
}
