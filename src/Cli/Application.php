<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Config\Configuration;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Filter;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Reporting\CompositeReporter;
use Greenlight\Reporting\GithubReporter;
use Greenlight\Reporting\JsonLinesReporter;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Reporting\Output\StreamOutput;
use Greenlight\Reporting\PlainReporter;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\TeamCityReporter;
use Greenlight\Reporting\TtyReporter;
use Greenlight\Runner\CpuCores;
use Greenlight\Runner\InProcessRunner;
use Greenlight\Runner\ParallelRunner;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Worker\WorkerProcess;

/**
 * The greenlight command. Parses arguments, loads greenlight.php, applies
 * command-line overrides, and dispatches to a command: run prints the
 * resolved plan, list-tests prints every discovered test id.
 *
 * Exit codes: 0 success, 1 failure (bad config, discovery error),
 * 64 usage error.
 *
 * @internal
 */
final readonly class Application
{
    public const string VERSION = 'dev-main';

    private const int EXIT_OK = 0;
    private const int EXIT_FAILURE = 1;
    private const int EXIT_USAGE = 64;

    private const string HELP = <<<'HELP'
        Greenlight

        Usage:
          greenlight [command] [options]

        Commands:
          run          Discover and execute tests (default)
          list-tests   List every discovered test id, one per line

        Options:
          --config=<path>    Use this config file instead of ./greenlight.php
          --workers=<n|auto> Worker process count
          --bail[=<n>]       Stop after <n> failures (default 1)
          --group=<name>     Only run this group; repeatable
          --seed=<n>         Randomize class order with this seed
          --reporter=<name>  Output format: tty, plain, junit, jsonl, github, teamcity; repeatable
          --dry-run          Print the resolved configuration without executing
          -h, --help         Show this help
          -V, --version      Show the version

        HELP;

    /**
     * @param \Closure(string): void $out
     * @param \Closure(string): void $err
     */
    public function __construct(
        private \Closure $out,
        private \Closure $err,
    ) {}

    public static function forStreams(): self
    {
        return new self(
            static function (string $text): void {
                \fwrite(\STDOUT, $text);
            },
            static function (string $text): void {
                \fwrite(\STDERR, $text);
            },
        );
    }

    /**
     * @param list<string> $argv the arguments after the script name
     */
    public function run(array $argv, string $workingDirectory, ?string $binPath = null): int
    {
        // Internal worker entry, spawned by the orchestrator. Bypasses the
        // normal parser; undocumented and carries no compatibility promise.
        if (($argv[0] ?? null) === '__worker') {
            if (\count($argv) !== 4 || $argv[1] === '' || $argv[2] === '' || $argv[3] === '') {
                ($this->err)("__worker requires <address> <workerId> <token>.\n");

                return self::EXIT_USAGE;
            }

            return new WorkerProcess()->run($argv[1], $argv[2], $argv[3]);
        }

        try {
            $arguments = $this->parser()->parse($argv);
        } catch (CliError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_USAGE;
        }

        if ($arguments->has('help')) {
            ($this->out)(self::HELP . "\n");

            return self::EXIT_OK;
        }

        if ($arguments->has('version')) {
            ($this->out)('Greenlight ' . self::VERSION . "\n");

            return self::EXIT_OK;
        }

        $command = $arguments->command ?? 'run';

        if ($command === 'run') {
            return $this->runCommand($arguments, $workingDirectory, $binPath);
        }

        if ($command === 'list-tests') {
            return $this->listTestsCommand($arguments, $workingDirectory);
        }

        ($this->err)(\sprintf("Unknown command '%s'. Run greenlight --help for the available commands.\n", $command));

        return self::EXIT_USAGE;
    }

    private function runCommand(ParsedArguments $arguments, string $workingDirectory, ?string $binPath = null): int
    {
        try {
            [$resolved, $configFile] = $this->loadConfiguration($arguments, $workingDirectory);
        } catch (CliError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_USAGE;
        } catch (ConfigFileError|InvalidConfiguration $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }

        if ($arguments->has('dry-run')) {
            ($this->out)(PlanFormatter::format($resolved, $configFile));

            return self::EXIT_OK;
        }

        try {
            $reporter = $this->buildReporter($arguments, $resolved->randomSeed);
        } catch (CliError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_USAGE;
        }

        $sink = new ReporterSink($reporter);
        $workers = $resolved->workers->fixed ?? CpuCores::count();
        $realBin = $binPath === null ? false : \realpath($binPath);

        try {
            if ($workers === 1 || $realBin === false) {
                $run = new InProcessRunner()->run($resolved, $this->directories($resolved, $workingDirectory), $sink);
            } else {
                $run = new ParallelRunner([\PHP_BINARY, $realBin], $workingDirectory)
                    ->run($resolved, $this->directories($resolved, $workingDirectory), $sink, $workers);
            }
        } catch (DiscoveryError|ProtocolError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }

        $reporter->finish();

        if ($run->plannedTests === 0) {
            ($this->err)("No tests found. A misconfigured run must not pass.\n");

            return self::EXIT_FAILURE;
        }

        return $run->summary->isSuccessful() ? self::EXIT_OK : self::EXIT_FAILURE;
    }

    /**
     * @throws CliError
     */
    private function buildReporter(ParsedArguments $arguments, ?int $seed): Reporter
    {
        $output = new StreamOutput(\STDOUT);
        $ansi = \function_exists('stream_isatty') && @\stream_isatty(\STDOUT);

        $names = $arguments->values('reporter');

        if ($names === []) {
            $names = [$ansi ? 'tty' : 'plain'];
        }

        $reporters = [];

        foreach ($names as $name) {
            $reporters[] = match ($name) {
                'tty' => new TtyReporter($output, $ansi, $seed),
                'plain' => new PlainReporter($output),
                'junit' => new JUnitReporter($output),
                'jsonl' => new JsonLinesReporter($output),
                'github' => new GithubReporter($output),
                'teamcity' => new TeamCityReporter($output),
                default => throw new CliError(\sprintf(
                    'Unknown reporter "%s". Available: tty, plain, junit, jsonl, github, teamcity.',
                    $name,
                )),
            };
        }

        return \count($reporters) === 1 ? $reporters[0] : new CompositeReporter($reporters);
    }

    private function listTestsCommand(ParsedArguments $arguments, string $workingDirectory): int
    {
        try {
            [$resolved] = $this->loadConfiguration($arguments, $workingDirectory);
        } catch (CliError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_USAGE;
        } catch (ConfigFileError|InvalidConfiguration $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }

        $filter = new Filter(includeGroups: $resolved->groups);

        try {
            $plan = new TestDiscoverer()->discover($this->directories($resolved, $workingDirectory), $filter, $resolved->randomSeed);
        } catch (DiscoveryError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }

        foreach ($plan->entries as $entry) {
            ($this->out)($entry->id . "\n");
        }

        ($this->out)(\sprintf("\n%d tests\n", \count($plan)));

        return self::EXIT_OK;
    }

    /**
     * @return array{Configuration, string}
     *
     * @throws CliError
     * @throws ConfigFileError
     * @throws InvalidConfiguration
     */
    private function loadConfiguration(ParsedArguments $arguments, string $workingDirectory): array
    {
        $overrides = CliOverrides::fromArguments($arguments);
        $loader = new ConfigLoader();
        $configArgument = $arguments->value('config');

        if ($configArgument !== null) {
            $configFile = $this->absolutePath($configArgument, $workingDirectory);
            $builder = $loader->loadFile($configFile);
        } else {
            $configFile = \rtrim($workingDirectory, '/') . '/' . ConfigLoader::FILE_NAME;
            $builder = $loader->loadFromDirectory($workingDirectory);
        }

        return [ConfigurationResolver::resolve($builder->build(), $overrides), $configFile];
    }

    /**
     * Configured top-level and suite paths, resolved against the working
     * directory and deduplicated.
     *
     * @return list<non-empty-string>
     */
    private function directories(Configuration $configuration, string $workingDirectory): array
    {
        $paths = $configuration->paths;

        foreach ($configuration->suites as $suite) {
            $paths = [...$paths, ...$suite->paths];
        }

        $directories = [];

        foreach ($paths as $path) {
            $absolute = $this->absolutePath($path, $workingDirectory);

            if ($absolute !== '' && !\in_array($absolute, $directories, true)) {
                $directories[] = $absolute;
            }
        }

        return $directories;
    }

    private function parser(): ArgumentParser
    {
        return new ArgumentParser([
            new OptionSpec('config', OptionValue::Required),
            new OptionSpec('workers', OptionValue::Required),
            new OptionSpec('bail', OptionValue::Optional),
            new OptionSpec('group', OptionValue::Required, repeatable: true),
            new OptionSpec('seed', OptionValue::Required),
            new OptionSpec('reporter', OptionValue::Required, repeatable: true),
            new OptionSpec('dry-run'),
            new OptionSpec('help', short: 'h'),
            new OptionSpec('version', short: 'V'),
        ]);
    }

    private function absolutePath(string $path, string $workingDirectory): string
    {
        if (\str_starts_with($path, '/')) {
            return $path;
        }

        return \rtrim($workingDirectory, '/') . '/' . $path;
    }
}
