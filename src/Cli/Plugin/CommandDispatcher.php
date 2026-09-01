<?php

declare(strict_types=1);

namespace Greenlight\Cli\Plugin;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Input\Definition;
use Greenlight\Cli\Output\Console;
use Greenlight\Command\ExitCode;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Coverage\CoverageError;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Internal\Wire\WireCommunicationFailed;
use Greenlight\Plugin\CommandDefinition;
use Greenlight\Plugin\CommandInvocation;
use Greenlight\Plugin\CommandProvider;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Reporting\ReportGenerationFailed;

/**
 * Creates a command-owned plugin registry and dispatches one selected command.
 *
 * @internal
 */
final readonly class CommandDispatcher
{
    /** @param non-empty-string $version */
    public function __construct(
        private Console $console,
        private string $version,
        private Definition $definition = new Definition(),
    ) {}

    /**
     * @param list<string> $argv
     * @throws CoverageError
     * @throws ReportGenerationFailed
     * @throws WireCommunicationFailed
     */
    public function dispatch(array $argv, string $workingDirectory, ?string $binPath): ExitCode
    {
        [$command, $commandIndex] = $this->selectedCommand($argv);
        $command ??= 'run';
        $bundled = PluginDefinition::fromFactory(
            fn(): BundledCommands => new BundledCommands($this->console, $this->version, $this->definition),
        );

        try {
            $catalog = $this->catalog([$bundled]);

            if (!$catalog->has($command)) {
                $catalog = $this->catalog([$bundled, ...$this->configuredPlugins($argv, $workingDirectory)]);
            }
        } catch (ConfigFileError|InvalidConfiguration|CommandSetupFailed $error) {
            $this->console->error($error->getMessage(), \in_array('--no-ansi', $argv, true));

            return ExitCode::failure();
        }

        $definition = $catalog->get($command);

        if (!$definition instanceof CommandDefinition) {
            $this->console->error(
                \sprintf("Unknown command '%s'. Use greenlight --help to list commands.", $command),
                \in_array('--no-ansi', $argv, true),
            );

            return ExitCode::usage();
        }

        $arguments = $argv;

        if ($commandIndex !== null) {
            \array_splice($arguments, $commandIndex, 1);
        }

        try {
            $exitCode = $definition->run(CommandInvocation::create(
                $command,
                $arguments,
                $workingDirectory,
                $binPath,
                $argv,
                $this->console->out(...),
                $this->console->err(...),
            ));
        } catch (CoverageError|ReportGenerationFailed|WireCommunicationFailed $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->console->error(\sprintf(
                'Command "%s" caused an error: %s',
                $command,
                $error->getMessage(),
            ), \in_array('--no-ansi', $argv, true));

            return ExitCode::failure();
        }

        return $exitCode;
    }

    /**
     * @param list<PluginDefinition> $plugins
     * @throws CommandSetupFailed
     */
    private function catalog(array $plugins): CommandCatalog
    {
        $definitions = [];

        foreach ($plugins as $pluginDefinition) {
            if (!$pluginDefinition->supports(CommandProvider::class)) {
                continue;
            }

            try {
                $plugin = $pluginDefinition->create();

                if (!$plugin instanceof CommandProvider) {
                    throw new \LogicException('The command provider definition created an incompatible plugin.');
                }

                $provided = $plugin->commands();
            } catch (\Throwable $error) {
                throw CommandSetupFailed::providerFailed($pluginDefinition->pluginClass, $error);
            }

            $position = 0;

            foreach ($provided as $definition) {
                ++$position;

                if (!$definition instanceof CommandDefinition) {
                    throw CommandSetupFailed::invalidDefinition($plugin::class, $position);
                }

                $definitions[] = $definition;
            }
        }

        return new CommandCatalog($definitions);
    }

    /**
     * @param list<string> $argv
     * @return list<PluginDefinition>
     * @throws ConfigFileError
     * @throws InvalidConfiguration
     */
    private function configuredPlugins(array $argv, string $workingDirectory): array
    {
        $configuredPath = null;

        foreach ($argv as $argument) {
            if (\str_starts_with($argument, '--config=')) {
                $configuredPath = \substr($argument, \strlen('--config='));
            }
        }

        $loader = new ConfigLoader();

        if ($configuredPath !== null) {
            $builder = $loader->loadFile(ConfigurationLoader::absolutePath($configuredPath, $workingDirectory));
        } else {
            $default = \rtrim($workingDirectory, '/') . '/' . ConfigLoader::FILE_NAME;

            if (!ErrorTrap::run(static fn(): bool => \is_file($default))) {
                return [];
            }

            $builder = $loader->loadFile($default);
        }

        return $builder->build()->execution->plugins;
    }

    /**
     * @param list<string> $argv
     * @return array{?string, ?int}
     */
    private function selectedCommand(array $argv): array
    {
        foreach ($argv as $index => $argument) {
            if (!\str_starts_with($argument, '-') || $argument === '-') {
                return [$argument, $index];
            }
        }

        return [null, null];
    }
}
