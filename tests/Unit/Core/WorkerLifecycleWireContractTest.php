<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Expect\Expect;

final class WorkerLifecycleWireContractTest
{
    #[Test]
    public function workerLifecycleEventsKeepTheirPublishedWireSchema(): void
    {
        Expect::that(new WorkerSpawned('worker-7', 321, 123.5)->toWire())
            ->because('worker-spawned payloads MUST keep their published field names')
            ->toBe([
                'workerId' => 'worker-7',
                'pid' => 321,
                'occurredAt' => 123.5,
            ])
            ->and(new WorkerRecycled('worker-7', RecycleReason::Crash, 124.5)->toWire())
            ->because('worker-recycled payloads MUST keep their published field names and values')
            ->toBe([
                'workerId' => 'worker-7',
                'reason' => 'crash',
                'occurredAt' => 124.5,
            ]);
    }

    #[Test]
    public function recycleReasonsKeepTheirPublishedWireValues(): void
    {
        Expect::that(\array_map(
            static fn(RecycleReason $reason): string => $reason->value,
            RecycleReason::cases(),
        ))
            ->because('worker recycle reasons MUST keep their machine-readable meanings')
            ->toBe([
                'test-count',
                'memory',
                'crash',
            ]);
    }
}
