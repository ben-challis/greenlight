<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Runner\Protocol\SocketChannel;

/**
 * Contains assignment state for crash attribution, resource release,
 * progress deadlines, and summary validation.
 *
 * @internal
 */
final class WorkerHandle
{
    public ?SocketChannel $channel = null;

    public bool $ready = false;

    public ?ExecutionPlan $assigned = null;

    public bool $isolatedAssignment = false;

    public ?ResourceLease $lease = null;

    private bool $hasRunAssignment = false;

    public ResultSummary $tally;

    /**
     * @var array<string, true> finished test ids, keyed by string form
     */
    public array $finished = [];

    public ?TestId $inFlight = null;

    public float $inFlightSince = 0.0;

    /**
     * @var non-negative-int
     */
    public int $inFlightAttempt = 0;

    public bool $done = false;

    public string $diagnostics = '';

    public readonly float $spawnedAt;

    public float $lastProgressAt;

    /**
     * @param non-empty-string $workerId
     * @param positive-int $channelNumber
     * @param resource $process
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        public readonly string $workerId,
        public readonly int $channelNumber,
        public readonly mixed $process,
        public readonly mixed $stdout,
        public readonly mixed $stderr,
    ) {
        $this->tally = new ResultSummary();
        $this->spawnedAt = \microtime(true);
        $this->lastProgressAt = $this->spawnedAt;
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

    public function isRunning(): bool
    {
        if (!\is_resource($this->process)) {
            return false;
        }

        $status = \proc_get_status($this->process);

        return $status['running'];
    }

    /**
     * Moves piped worker output to the bounded diagnostics buffer.
     */
    public function drainPipes(): void
    {
        ErrorTrap::run(function (): void {
            foreach ([$this->stdout, $this->stderr] as $pipe) {
                if (!\is_resource($pipe)) {
                    continue;
                }

                \stream_set_blocking($pipe, false);
                $bytes = \fread($pipe, 8192);

                if (\is_string($bytes) && $bytes !== '') {
                    $this->diagnostics = \substr($this->diagnostics . $bytes, -65536);
                }
            }
        });
    }

    public function terminate(): void
    {
        $this->channel?->close();

        if (\is_resource($this->process)) {
            ErrorTrap::run(function (): void {
                \proc_terminate($this->process, 9);
                \proc_close($this->process);
            });
        }
    }

    /**
     * Returns incomplete entries in the current assignment.
     *
     * The result excludes the active entry. Crash reassignment uses this
     * result because Greenlight does not automatically retry a crashed test.
     *
     * @return list<TestId>
     */
    public function unfinished(): array
    {
        $assigned = $this->assigned;

        if (!$assigned instanceof ExecutionPlan) {
            return [];
        }

        $remaining = [];

        foreach ($assigned->entries as $entry) {
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
