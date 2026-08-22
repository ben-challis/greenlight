<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;

/**
 * Allocates worker IDs and stops replacement loops.
 *
 * @internal
 */
final class WorkerSpawnBudget
{
    private int $spawned = 0;

    private readonly int $limit;

    /**
     * @param non-negative-int $plannedTests
     * @param positive-int $workerCount
     */
    public function __construct(int $plannedTests, int $workerCount)
    {
        // Worker replacement and crash containment can start replacement
        // processes. Permit only a small number for each planned test.
        $remaining = \PHP_INT_MAX - $plannedTests;
        $this->limit = $remaining < 16 || $workerCount > \intdiv($remaining - 16, 8)
            ? \PHP_INT_MAX
            : $plannedTests + $workerCount * 8 + 16;
    }

    /**
     * @return non-empty-string
     * @throws ProtocolError
     */
    public function nextWorkerId(): string
    {
        ++$this->spawned;

        if ($this->spawned > $this->limit) {
            throw ProtocolError::malformedFrame(\sprintf(
                'Greenlight started %d workers for this execution plan. '
                . 'This count indicates a worker replacement loop',
                $this->spawned,
            ));
        }

        return 'w-' . $this->spawned;
    }
}
