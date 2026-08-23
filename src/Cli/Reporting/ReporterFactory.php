<?php

declare(strict_types=1);

namespace Greenlight\Cli\Reporting;

use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Output\TerminalCapabilities;
use Greenlight\Cli\Output\TerminalRowsResolver;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Plugin\ReporterProvider;
use Greenlight\Reporting\CompositeReporter;
use Greenlight\Reporting\GithubReporter;
use Greenlight\Reporting\JsonLinesReporter;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Reporting\Output;
use Greenlight\Reporting\PlainReporter;
use Greenlight\Reporting\Profile\ProfileReporter;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\ReporterDefinition;
use Greenlight\Reporting\RunHeader;
use Greenlight\Reporting\Style;
use Greenlight\Reporting\TeamCityReporter;
use Greenlight\Reporting\TtyReporter;

/**
 * Builds the reporter catalog and fresh reporters for one command.
 *
 * @internal
 */
final readonly class ReporterFactory
{
    public function __construct(private Console $console) {}

    /**
     * @param list<PluginDefinition> $plugins
     * @param non-empty-string $version
     * @throws ReporterSetupFailed
     */
    public function catalog(ParsedArguments $arguments, array $plugins, ?int $seed, string $configFile, string $workingDirectory, bool $workerFallback = false, string $version = '0.0.0'): ReporterCatalog
    {
        $capabilities = $this->console->capabilities($arguments->has('no-ansi'), $arguments->has('ansi'));
        $prefix = \rtrim($workingDirectory, '/') . '/';
        $displayedConfig = \str_starts_with($configFile, $prefix) ? \substr($configFile, \strlen($prefix)) : $configFile;
        $header = new RunHeader($version, $displayedConfig, $seed, workerFallback: $workerFallback);
        $profile = $arguments->has('profile');
        $definitions = [
            new ReporterDefinition('tty', static function (Output $output) use ($capabilities, $header, $profile, $arguments): Reporter {
                $selectedCapabilities = $output instanceof ReporterOutput ? $output->capabilities : $capabilities;

                return new TtyReporter(
                    $output,
                    $selectedCapabilities->color,
                    $selectedCapabilities->interactive,
                    $header,
                    extendedSlowTests: $profile,
                    verbose: $arguments->has('verbose'),
                    terminalRows: TerminalRowsResolver::resolve(),
                );
            }),
            new ReporterDefinition('plain', static fn(Output $output): Reporter => new PlainReporter($output, $header, extendedSlowTests: $profile)),
            new ReporterDefinition('junit', static fn(Output $output): Reporter => new JUnitReporter($output)),
            new ReporterDefinition('jsonl', static fn(Output $output): Reporter => new JsonLinesReporter($output)),
            new ReporterDefinition('github', static fn(Output $output): Reporter => new GithubReporter($output)),
            new ReporterDefinition('teamcity', static fn(Output $output): Reporter => new TeamCityReporter($output)),
        ];

        foreach ($plugins as $pluginDefinition) {
            if (!$pluginDefinition->supports(ReporterProvider::class)) {
                continue;
            }
            try {
                $plugin = $pluginDefinition->create();
                if (!$plugin instanceof ReporterProvider) {
                    throw new \LogicException('The reporter provider definition created an incompatible plugin.');
                }
                $provided = $plugin->reporters();
            } catch (\Throwable $error) {
                throw ReporterSetupFailed::providerFailed($pluginDefinition->pluginClass, $error);
            }
            $position = 0;
            foreach ($provided as $definition) {
                ++$position;
                if (!$definition instanceof ReporterDefinition) {
                    throw ReporterSetupFailed::invalidDefinition($plugin::class, $position);
                }
                $definitions[] = $definition;
            }
        }
        return new ReporterCatalog($definitions);
    }

    /**
     * @throws CliError
     * @throws ReporterSetupFailed
     */
    public function outputs(ParsedArguments $arguments, ReporterCatalog $catalog, string $workingDirectory): ReporterOutputPlan
    {
        $standardCapabilities = $this->console->capabilities($arguments->has('no-ansi'), $arguments->has('ansi'));
        $fileCapabilities = TerminalCapabilities::detect(
            false,
            ['CI' => \getenv('CI'), 'NO_COLOR' => \getenv('NO_COLOR')],
            $arguments->has('no-ansi'),
            $arguments->has('ansi'),
        );

        return ReporterOutputPlan::create(
            $arguments->values('reporter'),
            $standardCapabilities->interactive || $standardCapabilities->color ? 'tty' : 'plain',
            $catalog,
            $this->console->stdout(),
            $workingDirectory,
            $standardCapabilities,
            $fileCapabilities,
        );
    }

    /**
     * @throws CliError
     * @throws ReporterSetupFailed
     */
    public function create(ParsedArguments $arguments, ReporterCatalog $catalog, ReporterOutputPlan $outputs): Reporter
    {
        $reporters = $outputs->createReporters($catalog);

        if ($arguments->has('profile')) {
            $reporters[] = new ProfileReporter(
                $outputs->standardOutput,
                new Style($outputs->standardOutput->capabilities->color),
            );
        }

        return \count($reporters) === 1 ? $reporters[0] : new CompositeReporter($reporters);
    }
}
