<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Output\ExitCode;
use Greenlight\Cli\Plugin\CommandDispatcher;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\Relay\SubprocessCoverage;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\ProcessPool\Worker\WorkerProcess;
use Greenlight\Internal\Wire\WireCommunicationFailed;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Reporting\StreamOutput;

/**
 * Uses exit code 0 for success. Uses 1 for a test or run failure. Uses 64 for
 * invalid command-line use.
 *
 * @internal
 */
final readonly class Application
{
    public const string VERSION = '0.0.0'; // x-release-please-version

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
     * @throws CoverageError
     * @throws ProtocolError
     * @throws ReportGenerationFailed
     * @throws WireCommunicationFailed
     */
    public function run(array $argv, string $workingDirectory, ?string $binPath = null): int
    {
        // The orchestrator starts this internal worker entry. It does not use
        // the normal parser. No documentation or compatibility guarantee
        // applies to it.
        if (($argv[0] ?? null) === '__worker') {
            if (\count($argv) !== 4 || $argv[1] === '' || $argv[2] === '' || $argv[3] === '') {
                $this->console->err("__worker requires <address> <workerId> <token>.\n");

                return ExitCode::USAGE;
            }

            return new WorkerProcess(isolateProcessGroup: true)->run($argv[1], $argv[2], $argv[3]);
        }

        // A run with coverage exports the relay variables to each child
        // process. A CLI process that inherits them reports its coverage
        // through the shared directory.
        $dump = SubprocessCoverage::begin();
        $dispatch = fn(): int => new CommandDispatcher($this->console, self::VERSION)->dispatch($argv, $workingDirectory, $binPath);

        if (!$dump instanceof SubprocessCoverage) {
            return $dispatch();
        }

        try {
            return $dispatch();
        } finally {
            $dump->write();
        }
    }
}
