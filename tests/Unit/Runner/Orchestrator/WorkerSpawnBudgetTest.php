<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\WorkerSpawnBudget;
use Greenlight\Runner\Protocol\ProtocolError;

final readonly class WorkerSpawnBudgetTest
{
    #[Test]
    public function workerIdsStopAtTheReplacementBudget(): void
    {
        $budget = new WorkerSpawnBudget(plannedTests: 1, workerCount: 1);

        Expect::that($budget->nextWorkerId())
            ->because('worker IDs start at one')
            ->toBe('w-1');

        $last = '';

        for ($worker = 2; $worker <= 25; ++$worker) {
            $last = $budget->nextWorkerId();
        }

        Expect::that($last)
            ->because('the replacement budget permits the final bounded worker')
            ->toBe('w-25');
        Expect::that($budget->nextWorkerId(...))
            ->because('the replacement budget MUST stop a worker loop')
            ->toThrow(
                ProtocolError::class,
                message: 'Malformed frame: Greenlight started 26 workers for this execution plan. '
                    . 'This count indicates a worker replacement loop.',
            );
    }

    #[Test]
    public function extremeWorkerCountsKeepTheReplacementBudgetBounded(): void
    {
        $budget = new WorkerSpawnBudget(plannedTests: 1, workerCount: \PHP_INT_MAX);

        Expect::that($budget->nextWorkerId())
            ->because('the replacement budget MUST remain usable for every accepted worker count')
            ->toBe('w-1');
    }
}
