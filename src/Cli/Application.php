<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Cli\Command\WatchRunCommand;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Plugin\CommandDispatcher;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\Relay\SubprocessCoverage;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\ProcessPool\Worker\WorkerProcess;
use Greenlight\Internal\Wire\WireCommunicationFailed;
use Greenlight\Plugin\CommandResult;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Reporting\StreamOutput;

/**
 * Uses exit code 0 for success. Uses 1 for a test or run failure.
 * Uses 64 for invalid command-line use. Interruption uses 128 plus the signal.
 *
 * @internal
 */
final readonly class Application
{
    public const string VERSION = '0.3.0'; // x-release-please-version

    private function __construct(private Console $console) {}

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public static function forStreams($stdout = null, $stderr = null): self
    {
        $stdout ??= \STDOUT;
        $stderr ??= \STDERR;
        $out = new StreamOutput($stdout);
        $err = new StreamOutput($stderr);

        return new self(new Console(
            $stdout,
            $stderr,
            static function (string $text) use ($out): void {
                $out->write($text);
            },
            static function (string $text) use ($err): void {
                $err->write($text);
            },
        ));
    }

    /**
     * @param list<string> $argv the arguments after the script name
     * @throws ProtocolError
     */
    public function run(array $argv, string $workingDirectory, ?string $binPath = null): int
    {
        require_once __DIR__ . '/../Expect/functions.php';

        if (($argv[0] ?? null) === '__watch-run') {
            $result = new WatchRunCommand($this->console)->run(\array_slice($argv, 1), $workingDirectory, $binPath);

            return ExitCode::fromCommandResult($result)->value();
        }

        // The orchestrator starts this internal worker entry. It does not use
        // the normal parser. No documentation or compatibility guarantee
        // applies to it.
        if (($argv[0] ?? null) === '__worker') {
            if (\count($argv) !== 4 || $argv[1] === '' || $argv[2] === '' || $argv[3] === '') {
                $this->console->err("__worker requires <address> <workerId> <token>.\n");

                return ExitCode::fromCommandResult(CommandResult::usage())->value();
            }

            $result = new WorkerProcess(isolateProcessGroup: true)->run($argv[1], $argv[2], $argv[3]);

            return ExitCode::fromCommandResult($result)->value();
        }

        // A run with coverage exports the relay variables to each child
        // process. A CLI process that inherits them reports its coverage
        // through the shared directory.
        $dump = SubprocessCoverage::begin();

        try {
            return ExitCode::fromCommandResult($this->dispatch($argv, $workingDirectory, $binPath))->value();
        } finally {
            $dump?->write();
        }
    }

    /**
     * Commands pass these failures up. They end the command, so the user gets
     * one diagnostic line and the failure exit code, not a PHP fatal error.
     *
     * @param list<string> $argv
     */
    private function dispatch(array $argv, string $workingDirectory, ?string $binPath): CommandResult
    {
        try {
            return new CommandDispatcher($this->console, self::VERSION)->dispatch($argv, $workingDirectory, $binPath);
        } catch (CoverageError|ReportGenerationFailed|WireCommunicationFailed $error) {
            $this->console->error($error->getMessage(), \in_array('--no-ansi', $argv, true));

            return CommandResult::failure();
        }
    }
}
