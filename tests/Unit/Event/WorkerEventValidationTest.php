<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Event;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Event\WorkerSpawned;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final readonly class WorkerEventValidationTest
{
    #[Test]
    public function workerEventsRetainAZeroWorkerId(): void
    {
        $event = new WorkerSpawned('0', 1, 1.0);
        $decoded = WorkerSpawned::fromWire(JsonWire::roundTrip($event->toWire()));

        Expect::that($event->workerId)
            ->because('a worker event MUST retain each non-empty worker ID')
            ->toBe('0');
        Expect::that($decoded->workerId)
            ->because('the worker ID MUST survive the wire')
            ->toBe('0');
    }

    #[Test]
    public function workerEventsRejectAnEmptyWorkerId(): void
    {
        Expect::that(static fn(): WorkerSpawned => new WorkerSpawned('', 1, 1.0))
            ->because('a spawned-worker event MUST identify its worker')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Worker ID cannot be empty.',
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
                message: 'Use a worker PID greater than zero.',
            );
    }

    #[Test]
    #[DataSet('nonPositivePids')]
    public function aSpawnedWorkerRejectsANonPositiveWirePid(int $pid): void
    {
        Expect::that(static fn(): WorkerSpawned => WorkerSpawned::fromWire([
            'workerId' => 'worker-1',
            'pid' => $pid,
            'occurredAt' => 1.0,
        ]))
            ->because('a spawned-worker wire event MUST identify a positive process ID')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use a worker PID greater than zero.',
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
