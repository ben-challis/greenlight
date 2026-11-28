<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Result\ResultSummary;
use Greenlight\Test\TestId;

/**
 * Contains orchestration state for one worker.
 *
 * This state has no native process or socket resources.
 *
 * One WorkerPhase value gives the lifecycle position. The transition methods
 * change it: connect(), markReady(), beginAssignment(), finishAssignment(),
 * requestStop(), and retire(). The worker also records if it connected and
 * if it reported Ready. A drain before Ready keeps these facts apart from the
 * phase.
 *
 * @internal
 */
final class WorkerState
{
    private WorkerPhase $phase = WorkerPhase::Spawned;

    private bool $connected = false;

    private bool $ready = false;

    public ?ExecutionPlan $assigned = null;

    public bool $isolatedAssignment = false;

    public ?ResourceLease $lease = null;

    private bool $hasRunAssignment = false;

    public ResultSummary $tally;

    /** @var array<string, true> */
    public array $finished = [];

    public ?TestId $inFlight = null;

    public float $inFlightSince = 0.0;

    public float $inFlightAttemptSince = 0.0;

    /** @var non-negative-int */
    public int $inFlightAttempt = 0;

    public readonly WorkerTimingRecorder $timing;

    public float $lastProgressAt;

    /**
     * @param non-empty-string $workerId
     * @param positive-int $channelNumber
     */
    public function __construct(
        public readonly string $workerId,
        public readonly int $channelNumber,
        public readonly float $spawnedAt,
    ) {
        $this->tally = new ResultSummary();
        $this->lastProgressAt = $spawnedAt;
        $this->timing = new WorkerTimingRecorder($spawnedAt);
    }

    public function phase(): WorkerPhase
    {
        return $this->phase;
    }

    /**
     * Records the authenticated hello. The worker then receives Bootstrap.
     */
    public function connect(float $at): void
    {
        $this->connected = true;
        $this->lastProgressAt = $at;

        if ($this->phase === WorkerPhase::Spawned) {
            $this->phase = WorkerPhase::Bootstrapping;
        }
    }

    /**
     * Records Ready. A worker that received Drain before Ready stays in the
     * Draining phase.
     */
    public function markReady(): void
    {
        $this->ready = true;

        if ($this->phase === WorkerPhase::Bootstrapping) {
            $this->phase = WorkerPhase::Idle;
        }
    }

    public function beginAssignment(ResourceLease $lease): void
    {
        if ($this->phase !== WorkerPhase::Draining && $this->phase !== WorkerPhase::Retiring) {
            $this->phase = WorkerPhase::Assigned;
        }

        $this->lease = $lease;
        $this->assigned = $lease->unit->plan;
        $this->isolatedAssignment = $lease->unit->isolated;
        $this->tally = new ResultSummary();
        $this->finished = [];
        $this->inFlight = null;
        $this->inFlightAttempt = 0;
    }

    public function finishAssignment(): void
    {
        if ($this->phase === WorkerPhase::Assigned) {
            $this->phase = WorkerPhase::Idle;
        }

        $this->hasRunAssignment = true;
        $this->lease = null;
        $this->assigned = null;
        $this->isolatedAssignment = false;
        $this->inFlight = null;
        $this->inFlightAttempt = 0;
    }

    public function isFresh(): bool
    {
        return !$this->hasRunAssignment;
    }

    /**
     * Records Drain. The worker can finish its current assignment.
     */
    public function requestStop(float $at): void
    {
        if ($this->phase !== WorkerPhase::Retiring) {
            $this->phase = WorkerPhase::Draining;
        }

        $this->lastProgressAt = $at;
    }

    public function retire(): void
    {
        $this->phase = WorkerPhase::Retiring;
    }

    public function isActive(): bool
    {
        return $this->phase !== WorkerPhase::Retiring;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function isStopRequested(): bool
    {
        return $this->phase === WorkerPhase::Draining;
    }

    /**
     * Tells if the worker is ready and waits for an assignment.
     */
    public function isIdle(): bool
    {
        return $this->phase === WorkerPhase::Idle;
    }

    /**
     * Tells if the worker can take an assignment now or after its bootstrap.
     */
    public function canTakeAssignment(): bool
    {
        return \in_array($this->phase, [WorkerPhase::Spawned, WorkerPhase::Bootstrapping, WorkerPhase::Idle], true);
    }

    /** @return list<TestId> */
    public function unfinished(): array
    {
        if (!$this->assigned instanceof ExecutionPlan) {
            return [];
        }

        $remaining = [];

        foreach ($this->assigned->entries as $entry) {
            $key = (string) $entry->id;

            if (isset($this->finished[$key])) {
                continue;
            }

            if ($this->inFlight instanceof TestId && $entry->id->equals($this->inFlight)) {
                continue;
            }

            $remaining[] = $entry->id;
        }

        return $remaining;
    }
}
