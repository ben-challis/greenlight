<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Event;

use Greenlight\Attribute\Test;
use Greenlight\Event\WorkerSpawned;
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
            ]);
    }
}
