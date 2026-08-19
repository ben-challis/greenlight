<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\Utf8;
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
    private const int MAX_DIAGNOSTIC_BYTES = 65_536;

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

    /** @var array{string, string} */
    private array $diagnosticCarry = ['', ''];

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
        $this->spawnedAt = \hrtime(true) / 1_000_000_000;
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
            foreach ([$this->stdout, $this->stderr] as $index => $pipe) {
                if (!\is_resource($pipe)) {
                    continue;
                }

                \stream_set_blocking($pipe, false);
                $bytes = \stream_get_contents($pipe);

                if (!\is_string($bytes)) {
                    continue;
                }

                [$complete, $this->diagnosticCarry[$index]] = $this->completeUtf8Prefix(
                    $this->diagnosticCarry[$index] . $bytes,
                );

                if (\feof($pipe) && $this->diagnosticCarry[$index] !== '') {
                    $complete .= $this->diagnosticCarry[$index];
                    $this->diagnosticCarry[$index] = '';
                }

                if ($complete !== '') {
                    $this->diagnostics = Utf8::tailBytes(
                        $this->diagnostics . $complete,
                        self::MAX_DIAGNOSTIC_BYTES,
                    );
                }
            }
        });
    }

    /**
     * @return array{string, string}
     */
    private function completeUtf8Prefix(string $value): array
    {
        if (\preg_match('//u', $value) === 1) {
            return [$value, ''];
        }

        $maxCarryBytes = \min(3, \strlen($value));

        for ($carryBytes = 1; $carryBytes <= $maxCarryBytes; ++$carryBytes) {
            $prefix = \substr($value, 0, -$carryBytes);
            $carry = \substr($value, -$carryBytes);

            if (\preg_match('//u', $prefix) === 1 && $this->isIncompleteUtf8Suffix($carry)) {
                return [$prefix, $carry];
            }
        }

        return [Utf8::scrub($value), ''];
    }

    private function isIncompleteUtf8Suffix(string $value): bool
    {
        $length = \strlen($value);
        $lead = \ord($value[0]);
        $expectedLength = match (true) {
            $lead >= 0xC2 && $lead <= 0xDF => 2,
            $lead >= 0xE0 && $lead <= 0xEF => 3,
            $lead >= 0xF0 && $lead <= 0xF4 => 4,
            default => 0,
        };

        if ($expectedLength === 0 || $length >= $expectedLength) {
            return false;
        }

        for ($index = 1; $index < $length; ++$index) {
            $byte = \ord($value[$index]);
            $minimum = match (true) {
                $index !== 1 => 0x80,
                $lead === 0xE0 => 0xA0,
                $lead === 0xF0 => 0x90,
                default => 0x80,
            };
            $maximum = match (true) {
                $index !== 1 => 0xBF,
                $lead === 0xED => 0x9F,
                $lead === 0xF4 => 0x8F,
                default => 0xBF,
            };

            if ($byte < $minimum || $byte > $maximum) {
                return false;
            }
        }

        return true;
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
