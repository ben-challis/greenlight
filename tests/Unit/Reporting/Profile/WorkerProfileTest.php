<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting\Profile;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Profile\WorkerProfile;

final class WorkerProfileTest
{
    #[Test]
    public function completeLifecycleAccumulatesWorkerMetrics(): void
    {
        $profile = new WorkerProfile();
        $profile->spawned(10.0);
        $profile->classStarted(12.0);

        Expect::value($profile->classFinished(13.25))
            ->because('the first class duration is measured')
            ->toBe(1.25);

        $profile->classStarted(14.0);

        Expect::value($profile->classFinished(15.75))
            ->because('the second class duration is measured')
            ->toBe(1.75);
        Expect::value($profile->busy)
            ->because('busy time accumulates every completed class')
            ->toBe(3.0);
        Expect::value($profile->classes)
            ->toBe(2);
        Expect::value($profile->openAt)
            ->toBeNull();
        Expect::value($profile->spawnedAt)
            ->toBe(10.0);
        Expect::value($profile->firstClassAt)
            ->toBe(12.0);
        Expect::value($profile->lastFinishAt)
            ->toBe(15.75);
        Expect::value($profile->bootLatency())
            ->toBe(2.0);
        Expect::value($profile->window())
            ->toBe(5.75);
        Expect::value($profile->utilizationPercent())
            ->because('utilization is rounded from accumulated busy time')
            ->toBe(52);
        Expect::value($profile->isolated)
            ->toBeFalse();
    }

    #[Test]
    public function repeatedLifecycleEventsPreserveTheInitialTimestamps(): void
    {
        $profile = new WorkerProfile();
        $profile->spawned(10.0);
        $profile->spawned(20.0);
        $profile->classStarted(12.0);
        $profile->classStarted(14.0);
        $profile->classFinished(15.0);

        Expect::value($profile->spawnedAt)
            ->because('repeated lifecycle events MUST NOT replace the first worker spawn time')
            ->toBe(10.0);
        Expect::value($profile->firstClassAt)
            ->because('repeated lifecycle events MUST NOT replace the first class start time')
            ->toBe(12.0);
        Expect::value($profile->bootLatency())
            ->because('boot latency MUST use the first observed lifecycle timestamps')
            ->toBe(2.0);
        Expect::value($profile->window())
            ->because('the worker window MUST start at the first observed spawn')
            ->toBe(5.0);
    }

    #[Test]
    public function incompleteTimingDataDoesNotInventMetrics(): void
    {
        $profile = new WorkerProfile();

        Expect::value($profile->bootLatency())
            ->because('incomplete timing data does not invent metrics')
            ->toBeNull();
        Expect::value($profile->window())
            ->toBe(0.0);
        Expect::value($profile->utilizationPercent())
            ->toBeNull();
    }

    #[Test]
    public function clockSkewKeepsProfileMetricsBounded(): void
    {
        $profile = new WorkerProfile();
        $profile->spawned(100.0);
        $profile->classStarted(90.0);
        $profile->classFinished(110.0);

        Expect::value($profile->bootLatency())
            ->because('worker clock skew MUST NOT produce negative boot latency')
            ->toBe(0.0);
        Expect::value($profile->window())
            ->toBe(10.0);
        Expect::value($profile->utilizationPercent())
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

        Expect::value($profile->busy)
            ->because('a reversed class timestamp MUST NOT produce negative busy time')
            ->toBe(0.0);
        Expect::value($profile->window())
            ->because('a reversed worker period MUST NOT produce a negative window')
            ->toBe(0.0);
        Expect::value($profile->utilizationPercent())
            ->toBeNull();
    }

    #[Test]
    public function isolatedClassMarksTheWorker(): void
    {
        $profile = new WorkerProfile();
        $profile->classStarted(10.0, isolated: true);

        Expect::value($profile->isolated)
            ->because('an isolated class MUST mark its worker')
            ->toBeTrue();
    }
}
