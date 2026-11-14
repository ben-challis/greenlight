<?php

declare(strict_types=1);

namespace Greenlight\Cli\Run;

use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Reporting\ReporterSink;
use Greenlight\Cli\Watch\ClassFailureTap;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Internal\Process\EmergencyCleanups;
use Greenlight\Internal\Process\GracefulShutdown;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\Ticking;

/**
 * Owns a fresh process for one watch iteration and relays its events to the
 * command reporters. The parent keeps reporter file streams open across runs.
 *
 * @internal
 */
final readonly class WatchRunProcess
{
    private const int FORCED_EXIT_GRACE_MICROSECONDS = 2_000_000;

    public function __construct(
        private Console $console,
        private GracefulShutdown $shutdown,
        private ?EmergencyCleanups $emergencyCleanups = null,
    ) {}

    /**
     * @param non-empty-string $binPath
     * @param list<non-empty-string> $priorityClasses
     * @return list<non-empty-string>
     * @throws WatchRunFailed
     */
    public function run(string $binPath, ParsedArguments $arguments, string $workingDirectory, Reporter $reporter, array $priorityClasses, ?int $seed): array
    {
        $command = [\PHP_BINARY, $binPath, '__watch-run', \json_encode($priorityClasses, \JSON_THROW_ON_ERROR)];

        foreach ($arguments->options as $name => $values) {
            foreach ($values as $value) {
                $command[] = '--' . $name . ($value === null ? '' : '=' . $value);
            }
        }

        if ($seed !== null && !$arguments->has('seed')) {
            $command[] = '--seed=' . $seed;
        }

        $pipes = [];
        $process = ErrorTrap::run(function () use ($command, $workingDirectory, &$pipes) {
            return \proc_open(
                $command,
                [0 => ['file', '/dev/null', 'r'], 1 => $this->console->stdout(), 2 => $this->console->stderr(), 3 => ['pipe', 'w']],
                $pipes,
                $workingDirectory,
                options: ['bypass_shell' => true],
            );
        });

        if (!\is_resource($process)) {
            throw WatchRunFailed::operation('Could not start the PHP process.');
        }

        $events = $pipes[3];
        $started = ErrorTrap::run(static fn() => \proc_get_status($process));
        $pid = \is_array($started) ? $started['pid'] : 0;
        $signaled = false;
        $cleanup = $this->emergencyCleanups?->register(static function () use ($process, $pid, &$signaled): void {
            self::forceStop($process, $pid, $signaled);
        });
        $tap = new ClassFailureTap(new ReporterSink($reporter));
        $buffer = '';
        $nonBlocking = false;

        try {
            if (!\stream_set_blocking($events, false)) {
                throw WatchRunFailed::operation('Could not read watch events without blocking.');
            }

            $nonBlocking = true;

            do {
                $signal = $this->shutdown->signal();

                if ($signal !== null && !$signaled) {
                    ErrorTrap::run(static fn() => \proc_terminate($process, $signal));
                    $signaled = true;
                }

                $read = [$events];
                $write = null;
                $except = null;
                ErrorTrap::run(static fn() => \stream_select($read, $write, $except, 0, 50_000));
                $chunk = \stream_get_contents($events);

                if ($chunk === false) {
                    throw WatchRunFailed::operation('Could not read the watch event stream.');
                }

                $buffer .= $chunk;

                while (($newline = \strpos($buffer, "\n")) !== false) {
                    $tap->emit(EventCodec::decodeJsonLine(\substr($buffer, 0, $newline)));
                    $buffer = \substr($buffer, $newline + 1);
                }

                if ($reporter instanceof Ticking) {
                    $reporter->tick(\microtime(true));
                }

                $status = \proc_get_status($process);
            } while ($status['running'] || $chunk !== '');

            if ($buffer !== '' || $status['exitcode'] !== 0) {
                throw WatchRunFailed::operation('The PHP process did not complete the watch iteration.');
            }

            $reporter->finish();

            return $tap->failedClasses();
        } catch (\Throwable $failure) {
            throw $failure instanceof WatchRunFailed
                ? $failure
                : WatchRunFailed::operation($failure->getMessage(), $failure);
        } finally {
            if ($cleanup !== null) {
                $this->emergencyCleanups?->unregister($cleanup);
            }

            $status = \proc_get_status($process);

            if ($status['running']) {
                ErrorTrap::run(static fn() => \proc_terminate($process));
                $deadline = \hrtime(true) + 5_000_000_000;

                do {
                    if ($nonBlocking) {
                        ErrorTrap::run(static fn() => \stream_get_contents($events));
                    }
                    \usleep(10_000);
                    $status = \proc_get_status($process);
                } while ($status['running'] && \hrtime(true) < $deadline);

                if ($status['running']) {
                    ErrorTrap::run(static fn() => \proc_terminate($process, 9));
                }
            }

            \fclose($events);
            \proc_close($process);
        }
    }

    /**
     * Stops the child after a forced exit request. The child runs in its own
     * process group, so the terminal does not signal it or its workers. The
     * child must get two signals: the second makes it kill its workers
     * before it exits. When the parent did not forward the first signal yet,
     * the child gets both now. A child that does not exit in time gets
     * SIGKILL for its whole process group.
     *
     * @param resource $process
     */
    private static function forceStop(mixed $process, int $pid, bool $forwarded): void
    {
        if ($pid < 1 || !\function_exists('posix_kill')) {
            return;
        }

        if (!$forwarded) {
            ErrorTrap::run(static fn(): bool => \posix_kill($pid, \SIGTERM));
            // The child handles its first signal before the second one.
            \usleep(50_000);
        }

        ErrorTrap::run(static fn(): bool => \posix_kill($pid, \SIGTERM));
        $deadline = \hrtime(true) + self::FORCED_EXIT_GRACE_MICROSECONDS * 1_000;

        while (\hrtime(true) < $deadline) {
            $status = ErrorTrap::run(static fn() => \proc_get_status($process));

            if (!\is_array($status) || !$status['running']) {
                break;
            }

            \usleep(20_000);
        }

        ErrorTrap::run(static fn(): bool => \posix_kill(-$pid, \SIGKILL));
        ErrorTrap::run(static fn(): bool => \posix_kill($pid, \SIGKILL));
    }
}
