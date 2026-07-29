<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Runner\Protocol\ProtocolError;

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
        $this->limit = $plannedTests + $workerCount * 8 + 16;
    }

    /**
     * @return non-empty-string
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
