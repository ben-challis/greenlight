<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Expect\Expect;

final readonly class WorkerEventValidationTest
{
    #[Test]
    public function workerEventsRejectAnEmptyWorkerId(): void
    {
        Expect::that(static fn(): WorkerSpawned => new WorkerSpawned('', 1, 1.0))
            ->because('a spawned-worker event MUST identify its worker')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Worker ID MUST NOT be empty.',
            )
            ->and(static fn(): WorkerRecycled => new WorkerRecycled('', RecycleReason::TestCount, 1.0))
            ->because('a recycled-worker event MUST identify its worker')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Worker ID MUST NOT be empty.',
            );
    }

    #[Test]
    #[DataSet('nonPositivePids')]
    public function aSpawnedWorkerRejectsANonPositivePid(int $pid): void
    {
        Expect::that(static fn(): WorkerSpawned => new WorkerSpawned('worker-1', $pid, 1.0))
            ->because('a spawned-worker event MUST identify a positive process ID')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Worker PID MUST be greater than zero.',
            );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositivePids(): iterable
    {
        yield 'zero' => [0];

        yield 'negative' => [-1];
    }
}
