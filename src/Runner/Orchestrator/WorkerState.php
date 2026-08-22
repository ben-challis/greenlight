<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Result\ResultSummary;
use Greenlight\Test\TestId;

/**
 * Contains orchestration state for one worker.
 *
 * This state has no native process or socket resources.
 *
 * @internal
 */
final class WorkerState
{
    public bool $connected = false;

    public bool $ready = false;

    public bool $retiring = false;

    public bool $stopRequested = false;

    public ?ExecutionPlan $assigned = null;

    public bool $isolatedAssignment = false;

    public ?ResourceLease $lease = null;

    private bool $hasRunAssignment = false;

    public ResultSummary $tally;

    /** @var array<string, true> */
    public array $finished = [];

    public ?TestId $inFlight = null;

    public float $inFlightSince = 0.0;

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

    public function beginAssignment(ResourceLease $lease): void
    {
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

    public function isActive(): bool
    {
        return !$this->retiring;
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
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
