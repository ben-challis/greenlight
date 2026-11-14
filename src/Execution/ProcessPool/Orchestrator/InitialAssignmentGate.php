<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

/**
 * Controls the first assignments of the initial workers.
 *
 * The first spawns up to the initial worker target are initial workers.
 * Until each initial worker has its first assignment, a worker that finished
 * an assignment does not take more work. Thus, the first wave is fair.
 *
 * In AfterAllReady mode, the barrier also stays closed until the initial
 * worker count is ready. In Progressive mode, the barrier is open at the
 * start.
 *
 * @internal
 */
final class InitialAssignmentGate
{
    private bool $barrierPassed;

    /** @var non-negative-int */
    private int $spawnedWorkers = 0;

    /**
     * @var array<non-empty-string, true> initial workers that have not received their first assignment
     */
    private array $pending = [];

    /**
     * @param positive-int $initialWorkerTarget
     */
    public function __construct(
        private readonly int $initialWorkerTarget,
        InitialWorkerAssignment $assignment,
    ) {
        $this->barrierPassed = $assignment === InitialWorkerAssignment::Progressive;
    }

    /**
     * Records a spawn. Only the first spawns are initial workers. A
     * replacement does not close the gate again.
     *
     * @param non-empty-string $workerId
     */
    public function spawned(string $workerId): void
    {
        ++$this->spawnedWorkers;

        if ($this->spawnedWorkers <= $this->initialWorkerTarget) {
            $this->pending[$workerId] = true;
        }
    }

    public function barrierPassed(): bool
    {
        return $this->barrierPassed;
    }

    /**
     * Opens the barrier when enough initial workers are ready.
     *
     * @return bool True when the barrier is open.
     */
    public function openBarrier(int $readyWorkers): bool
    {
        if ($readyWorkers >= $this->initialWorkerTarget) {
            $this->barrierPassed = true;
        }

        return $this->barrierPassed;
    }

    /**
     * Tells if a worker that finished an assignment must wait for the first
     * assignment of an initial worker.
     */
    public function holdsReturningWorkers(): bool
    {
        return $this->pending !== [];
    }

    /**
     * Removes a worker from the initial workers that wait for a first
     * assignment. The worker received its first assignment or stopped.
     *
     * @param non-empty-string $workerId
     *
     * @return bool True when this change can release waiting workers.
     */
    public function release(string $workerId): bool
    {
        if (!isset($this->pending[$workerId])) {
            return false;
        }

        unset($this->pending[$workerId]);

        return true;
    }
}
