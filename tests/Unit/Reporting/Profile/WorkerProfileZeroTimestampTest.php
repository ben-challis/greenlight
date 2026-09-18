<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting\Profile;

use Greenlight\Attribute\Test;
use Greenlight\Reporting\Profile\WorkerProfile;

use function Greenlight\expect;

final readonly class WorkerProfileZeroTimestampTest
{
    #[Test]
    public function zeroLifecycleTimestampsRemainMeasured(): void
    {
        $profile = new WorkerProfile();
        $profile->spawned(0.0);
        $profile->classStarted(0.0);

        expect($profile->classFinished(0.5))
            ->because('a zero class-start timestamp is known timing data')
            ->toBe(0.5);
        expect($profile->busy)->toBe(0.5);
        expect($profile->bootLatency())->toBe(0.0);
        expect($profile->window())->toBe(0.5);
        expect($profile->utilizationPercent())->toBe(100);
    }
}
