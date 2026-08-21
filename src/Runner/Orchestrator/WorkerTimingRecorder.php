<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Core\Event\WorkerTiming;

/**
 * Records orchestrator protocol transitions for one worker process.
 *
 * Each transition has constant cost. The recorder does not run in test or
 * scheduler polling loops.
 *
 * @internal
 */
final class WorkerTimingRecorder
{
    private ?float $helloAt = null;

    private ?float $readyAt = null;

    private ?float $firstAssignmentAt = null;

    private ?float $lastAssignmentFinishedAt = null;

    private int $assignmentGaps = 0;

    private float $assignmentGapSeconds = 0.0;

    private ?WorkerIdleReason $idleReason = null;

    private ?float $idleSince = null;

    private float $bootstrapBarrierSeconds = 0.0;

    private float $resourceCapacitySeconds = 0.0;

    private float $noQueuedWorkSeconds = 0.0;

    private ?float $retirementRequestedAt = null;

    private ?float $exitObservedAt = null;

    public function __construct(private readonly float $spawnedAt) {}

    public function hello(float $at): void
    {
        $this->helloAt ??= $at;
    }

    public function ready(float $at): void
    {
        $this->readyAt ??= $at;
    }

    public function wait(WorkerIdleReason $reason, float $at): void
    {
        if ($this->idleReason === $reason) {
            return;
        }

        $wasIdle = $this->idleReason instanceof WorkerIdleReason;
        $this->finishIdle($at);
        $this->idleReason = $reason;
        $this->idleSince = $wasIdle ? $at : ($this->lastAssignmentFinishedAt ?? $at);
    }

    public function assigned(float $at): void
    {
        $this->finishIdle($at);
        $this->firstAssignmentAt ??= $at;

        if ($this->lastAssignmentFinishedAt !== null) {
            ++$this->assignmentGaps;
            $this->assignmentGapSeconds = $this->add(
                $this->assignmentGapSeconds,
                $this->between($this->lastAssignmentFinishedAt, $at),
            );
            $this->lastAssignmentFinishedAt = null;
        }
    }

    public function assignmentFinished(float $at): void
    {
        $this->lastAssignmentFinishedAt = $at;
    }

    public function retirementRequested(float $at): void
    {
        $this->finishIdle($at);
        $this->retirementRequestedAt ??= $at;
    }

    public function exitObserved(float $at): void
    {
        $this->finishIdle($at);
        $this->exitObservedAt ??= $at;
    }

    /**
     * @param non-empty-string $workerId
     */
    public function snapshot(string $workerId): WorkerTiming
    {
        return new WorkerTiming(
            $workerId,
            $this->helloAt === null ? null : $this->between($this->spawnedAt, $this->helloAt),
            $this->helloAt === null || $this->readyAt === null ? null : $this->between($this->helloAt, $this->readyAt),
            $this->readyAt === null || $this->firstAssignmentAt === null ? null : $this->between($this->readyAt, $this->firstAssignmentAt),
            $this->assignmentGaps,
            $this->assignmentGapSeconds,
            $this->bootstrapBarrierSeconds,
            $this->resourceCapacitySeconds,
            $this->noQueuedWorkSeconds,
            $this->retirementRequestedAt === null || $this->exitObservedAt === null
                ? null
                : $this->between($this->retirementRequestedAt, $this->exitObservedAt),
        );
    }

    private function finishIdle(float $at): void
    {
        if (!$this->idleReason instanceof WorkerIdleReason || $this->idleSince === null) {
            return;
        }

        $duration = $this->between($this->idleSince, $at);

        match ($this->idleReason) {
            WorkerIdleReason::BootstrapBarrier => $this->bootstrapBarrierSeconds = $this->add($this->bootstrapBarrierSeconds, $duration),
            WorkerIdleReason::ResourceCapacity => $this->resourceCapacitySeconds = $this->add($this->resourceCapacitySeconds, $duration),
            WorkerIdleReason::NoQueuedWork => $this->noQueuedWorkSeconds = $this->add($this->noQueuedWorkSeconds, $duration),
        };

        $this->idleReason = null;
        $this->idleSince = null;
    }

    private function between(float $start, float $finish): float
    {
        $duration = $finish - $start;

        if (!\is_finite($duration) || $duration <= 0.0) {
            return $duration > 0.0 ? \PHP_FLOAT_MAX : 0.0;
        }

        return $duration;
    }

    private function add(float $left, float $right): float
    {
        if ($left > \PHP_FLOAT_MAX - $right) {
            return \PHP_FLOAT_MAX;
        }

        return $left + $right;
    }
}
