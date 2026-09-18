<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerSpawnBudget;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;

use function Greenlight\expect;

final readonly class WorkerSpawnBudgetTest
{
    #[Test]
    public function workerIdsStopAtTheReplacementBudget(): void
    {
        $budget = new WorkerSpawnBudget(plannedTests: 1, workerCount: 1);

        expect($budget->nextWorkerId())
            ->because('worker IDs start at one')
            ->toBe('w-1');

        $last = '';

        for ($worker = 2; $worker <= 25; ++$worker) {
            $last = $budget->nextWorkerId();
        }

        expect($last)
            ->because('the replacement budget permits the final bounded worker')
            ->toBe('w-25');
        expect()->calling($budget->nextWorkerId(...))
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

        expect($budget->nextWorkerId())
            ->because('the replacement budget MUST remain usable for every accepted worker count')
            ->toBe('w-1');
    }
}
