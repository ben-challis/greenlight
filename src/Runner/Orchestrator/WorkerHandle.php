<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Runner\Protocol\SocketChannel;

/**
 * One spawned worker: its process, its channel once authenticated, its
 * current slice, and the orchestrator-side tally used for crash attribution
 * and summary cross-checks.
 *
 * @internal
 */
final class WorkerHandle
{
    public ?SocketChannel $channel = null;

    public ?ExecutionPlan $slice = null;

    public ResultSummary $tally;

    /**
     * @var array<string, true> finished test ids, keyed by string form
     */
    public array $finished = [];

    public ?TestId $inFlight = null;

    public float $inFlightSince = 0.0;

    public bool $done = false;

    public string $diagnostics = '';

    /**
     * @param non-empty-string $workerId
     * @param resource $process
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        public readonly string $workerId,
        public readonly mixed $process,
        public readonly mixed $stdout,
        public readonly mixed $stderr,
    ) {
        $this->tally = new ResultSummary();
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
     * Drains piped worker output into the bounded diagnostics buffer.
     */
    public function drainPipes(): void
    {
        foreach ([$this->stdout, $this->stderr] as $pipe) {
            if (!\is_resource($pipe)) {
                continue;
            }

            \stream_set_blocking($pipe, false);
            $bytes = @\fread($pipe, 8192);

            if (\is_string($bytes) && $bytes !== '') {
                $this->diagnostics = \substr($this->diagnostics . $bytes, -65536);
            }
        }
    }

    public function terminate(): void
    {
        $this->channel?->close();

        if (\is_resource($this->process)) {
            @\proc_terminate($this->process, 9);
            @\proc_close($this->process);
        }
    }

    /**
     * Entries of the current slice that have not finished, excluding the one
     * in flight (used for crash reassignment: crashed tests are never
     * retried automatically).
     *
     * @return list<TestId>
     */
    public function unfinished(): array
    {
        $slice = $this->slice;

        if (!$slice instanceof ExecutionPlan) {
            return [];
        }

        $remaining = [];

        foreach ($slice->entries as $entry) {
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
