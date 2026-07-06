<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\ConfigFileNotFound;
use Greenlight\Config\ConfigLoader;
use Greenlight\Config\InvalidConfigFile;
use Greenlight\Config\InvalidConfiguration;

/**
 * The greenlight command. Parses arguments, loads greenlight.php, applies
 * command-line overrides, and dispatches to a command. The only command
 * with behaviour today is run, which prints the resolved plan; list-tests
 * is registered but not implemented.
 *
 * Exit codes: 0 success, 1 failure (bad config, unimplemented command),
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
          run          Load the configuration and print the resolved run plan (default)
          list-tests   List discovered tests (not implemented yet)

        Options:
          --config=<path>    Use this config file instead of ./greenlight.php
          --workers=<n|auto> Worker process count
          --bail[=<n>]       Stop after <n> failures (default 1)
          --group=<name>     Only run this group; repeatable
          --seed=<n>         Randomize class order with this seed
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
            ($this->err)("list-tests is not implemented yet.\n");

            return self::EXIT_FAILURE;
        }

        ($this->err)(\sprintf("Unknown command '%s'. Run greenlight --help for the available commands.\n", $command));

        return self::EXIT_USAGE;
    }

    private function runCommand(ParsedArguments $arguments, string $workingDirectory): int
    {
        try {
            $overrides = CliOverrides::fromArguments($arguments);
        } catch (CliError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_USAGE;
        }

        $loader = new ConfigLoader();

        try {
            $configArgument = $arguments->value('config');

            if ($configArgument !== null) {
                $configFile = $this->absolutePath($configArgument, $workingDirectory);
                $builder = $loader->loadFile($configFile);
            } else {
                $configFile = \rtrim($workingDirectory, '/') . '/' . ConfigLoader::FILE_NAME;
                $builder = $loader->loadFromDirectory($workingDirectory);
            }

            $configuration = $builder->build();
        } catch (ConfigFileNotFound|InvalidConfigFile|InvalidConfiguration $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }

        $resolved = ConfigurationResolver::resolve($configuration, $overrides);

        ($this->out)(PlanFormatter::format($resolved, $configFile));

        return self::EXIT_OK;
    }

    private function parser(): ArgumentParser
    {
        return new ArgumentParser([
            new OptionSpec('config', OptionValue::Required),
            new OptionSpec('workers', OptionValue::Required),
            new OptionSpec('bail', OptionValue::Optional),
            new OptionSpec('group', OptionValue::Required, repeatable: true),
            new OptionSpec('seed', OptionValue::Required),
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
