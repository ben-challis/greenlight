<?php

declare(strict_types=1);

namespace Greenlight\Cli\Run;

use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Reporting\ReporterSink;
use Greenlight\Cli\Watch\ClassFailureTap;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Internal\Php\ErrorTrap;
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
    public function __construct(private Console $console, private GracefulShutdown $shutdown) {}

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
        $tap = new ClassFailureTap(new ReporterSink($reporter));
        $buffer = '';
        $signaled = false;
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
            if (\proc_get_status($process)['running']) {
                ErrorTrap::run(static fn() => \proc_terminate($process));
                $deadline = \hrtime(true) + 5_000_000_000;

                while (\proc_get_status($process)['running'] && \hrtime(true) < $deadline) {
                    if ($nonBlocking) {
                        ErrorTrap::run(static fn() => \stream_get_contents($events));
                    }
                    \usleep(10_000);
                }

                if (\proc_get_status($process)['running']) {
                    ErrorTrap::run(static fn() => \proc_terminate($process, 9));
                }
            }

            \fclose($events);
            \proc_close($process);
        }
    }
}
