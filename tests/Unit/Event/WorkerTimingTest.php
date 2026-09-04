<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Event;

use Greenlight\Attribute\Test;
use Greenlight\Event\WorkerTiming;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final readonly class WorkerTimingTest
{
    #[Test]
    public function workerTimingSurvivesTheWire(): void
    {
        $timing = new WorkerTiming('worker-2', 0.1, 0.2, 0.3, 2, 0.4, 0.5, 0.6, 0.7, 0.8);

        Expect::that(WorkerTiming::fromWire(JsonWire::roundTrip($timing->toWire()))->toWire())
            ->because('worker timing MUST preserve each lifecycle and idle duration')
            ->toBe($timing->toWire());
    }

    #[Test]
    public function workerTimingRejectsNegativeDurations(): void
    {
        Expect::that(static fn(): WorkerTiming => new WorkerTiming(
            'worker-2',
            -0.1,
            null,
            null,
            0,
            0.0,
            0.0,
            0.0,
            0.0,
            null,
        ))
            ->because('worker timing durations MUST be nonnegative')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use finite, nonnegative worker timing durations.',
            );
    }
}
