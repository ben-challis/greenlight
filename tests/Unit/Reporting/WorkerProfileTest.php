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

    #[Test]
    public function clockSkewKeepsProfileMetricsBounded(): void
    {
        $profile = new WorkerProfile();
        $profile->spawned(100.0);
        $profile->classStarted(90.0);
        $profile->classFinished(110.0);

        Expect::that($profile->bootLatency())
            ->because('worker clock skew MUST NOT produce negative boot latency')
            ->toBe(0.0)
            ->and($profile->window())
            ->toBe(10.0)
            ->and($profile->utilizationPercent())
            ->because('worker utilization MUST stay within its percentage range')
            ->toBe(100);
    }

    #[Test]
    public function reversedClassTimestampsDoNotProduceNegativeMetrics(): void
    {
        $profile = new WorkerProfile();
        $profile->spawned(20.0);
        $profile->classStarted(15.0);
        $profile->classFinished(14.0);

        Expect::that($profile->busy)
            ->because('a reversed class timestamp MUST NOT produce negative busy time')
            ->toBe(0.0)
            ->and($profile->window())
            ->because('a reversed worker period MUST NOT produce a negative window')
            ->toBe(0.0)
            ->and($profile->utilizationPercent())
            ->toBeNull();
    }
}
