<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Wire\Utf8;
use Greenlight\Runner\Protocol\SocketChannel;

/**
 * Owns one native worker process, its protocol channel, and diagnostics.
 *
 * @internal
 */
final class WorkerHandle
{
    private const int MAX_DIAGNOSTIC_BYTES = 65_536;

    public ?SocketChannel $channel = null;

    public WorkerLifecycle $lifecycle = WorkerLifecycle::Active;

    private float $retirementDeadline = 0.0;

    public string $diagnostics = '';

    /** @var array{string, string} */
    private array $diagnosticCarry = ['', ''];

    public readonly float $spawnedAt;

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
        $this->spawnedAt = \hrtime(true) / 1_000_000_000;
    }

    public function isRunning(): bool
    {
        if (!\is_resource($this->process)) {
            return false;
        }

        $status = \proc_get_status($this->process);

        return $status['running'];
    }

    public function isActive(): bool
    {
        return $this->lifecycle === WorkerLifecycle::Active;
    }

    public function isRetiring(): bool
    {
        return $this->lifecycle === WorkerLifecycle::Retiring
            || $this->lifecycle === WorkerLifecycle::Killing;
    }

    public function retire(float $now, float $graceSeconds): void
    {
        if (!$this->isActive()) {
            return;
        }

        $this->lifecycle = WorkerLifecycle::Retiring;
        $this->retirementDeadline = $now + $graceSeconds;
        $this->drainPipes();
        $this->channel?->close();
    }

    public function kill(float $now): void
    {
        $this->retire($now, 0.0);

        if ($this->lifecycle !== WorkerLifecycle::Retiring) {
            return;
        }

        $this->sendKillSignal();
    }

    /**
     * Advances process retirement without waiting for the process.
     *
     * @return bool True when the process handle is fully reaped.
     */
    public function reap(float $now): bool
    {
        if ($this->lifecycle === WorkerLifecycle::Reaped) {
            return true;
        }

        if (!$this->isRetiring()) {
            return false;
        }

        $this->drainPipes();

        if ($this->isRunning()) {
            if ($this->lifecycle === WorkerLifecycle::Retiring && $now >= $this->retirementDeadline) {
                $this->sendKillSignal();
            }

            return false;
        }

        $this->drainPipes();
        $this->closeDiagnosticPipes();

        if (\is_resource($this->process)) {
            ErrorTrap::run(fn() => \proc_close($this->process));
        }

        $this->lifecycle = WorkerLifecycle::Reaped;

        return true;
    }

    private function sendKillSignal(): void
    {
        if (\is_resource($this->process)) {
            ErrorTrap::run(fn() => \proc_terminate($this->process, 9));
        }

        $this->lifecycle = WorkerLifecycle::Killing;
    }

    private function closeDiagnosticPipes(): void
    {
        foreach ([$this->stdout, $this->stderr] as $pipe) {
            if (\is_resource($pipe)) {
                ErrorTrap::run(static fn() => \fclose($pipe));
            }
        }
    }

    /**
     * Moves piped worker output to the bounded diagnostics buffer.
     */
    public function drainPipes(): void
    {
        ErrorTrap::run(function () {
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
            ErrorTrap::run(function () {
                \proc_terminate($this->process, 9);
                \proc_close($this->process);
            });
        }

        $this->lifecycle = WorkerLifecycle::Reaped;
    }

}
