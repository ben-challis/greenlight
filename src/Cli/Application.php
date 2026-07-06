<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\ConfigFileNotFound;
use Greenlight\Config\ConfigLoader;
use Greenlight\Config\Configuration;
use Greenlight\Config\InvalidConfigFile;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Filter;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Runner\InProcessRunner;

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
    public function run(array $argv, string $workingDirectory): int
    {
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
            return $this->runCommand($arguments, $workingDirectory);
        }

        if ($command === 'list-tests') {
            return $this->listTestsCommand($arguments, $workingDirectory);
        }

        ($this->err)(\sprintf("Unknown command '%s'. Run greenlight --help for the available commands.\n", $command));

        return self::EXIT_USAGE;
    }

    private function runCommand(ParsedArguments $arguments, string $workingDirectory): int
    {
        try {
            [$resolved, $configFile] = $this->loadConfiguration($arguments, $workingDirectory);
        } catch (CliError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_USAGE;
        } catch (ConfigFileNotFound|InvalidConfigFile|InvalidConfiguration $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }

        if ($arguments->has('dry-run')) {
            ($this->out)(PlanFormatter::format($resolved, $configFile));

            return self::EXIT_OK;
        }

        $printer = new DotPrinter($this->out);

        try {
            $run = new InProcessRunner()->run($resolved, $this->directories($resolved, $workingDirectory), $printer);
        } catch (DiscoveryError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }

        $printer->finish();

        if ($run->plannedTests === 0) {
            ($this->err)("No tests found. A misconfigured run must not pass.\n");

            return self::EXIT_FAILURE;
        }

        $summary = $run->summary;
        ($this->out)(\sprintf(
            "\n%d tests: %d passed, %d failed, %d errored, %d skipped (%.3fs)\n",
            $summary->total(),
            $summary->passed,
            $summary->failed,
            $summary->errored,
            $summary->skipped,
            $run->durationSeconds,
        ));

        if ($run->seed !== null) {
            ($this->out)(\sprintf("Randomized order with seed %d.\n", $run->seed));
        }

        return $summary->isSuccessful() ? self::EXIT_OK : self::EXIT_FAILURE;
    }

    private function listTestsCommand(ParsedArguments $arguments, string $workingDirectory): int
    {
        try {
            [$resolved] = $this->loadConfiguration($arguments, $workingDirectory);
        } catch (CliError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_USAGE;
        } catch (ConfigFileNotFound|InvalidConfigFile|InvalidConfiguration $error) {
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
     * @throws ConfigFileNotFound
     * @throws InvalidConfigFile
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
