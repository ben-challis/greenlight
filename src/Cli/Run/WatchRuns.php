<?php

declare(strict_types=1);

namespace Greenlight\Cli\Run;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Configuration\LoadedConfiguration;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Reporting\ReporterCatalog;
use Greenlight\Cli\Reporting\ReporterFactory;
use Greenlight\Cli\Reporting\ReporterOutputPlan;
use Greenlight\Cli\Reporting\ReporterSetupFailed;
use Greenlight\Cli\Watch\Debouncer;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Cli\Watch\StdinKeyInput;
use Greenlight\Cli\Watch\SystemWatchClock;
use Greenlight\Cli\Watch\WatchLoop;
use Greenlight\Cli\Watch\WatchPathMatcher;
use Greenlight\Cli\Watch\WatchSourceFailed;
use Greenlight\Cli\Watch\WatchSourceRuntime;
use Greenlight\Execution\RunPolicyError;
use Greenlight\Execution\Worker\LeakDetector;
use Greenlight\Internal\Process\GracefulShutdown;
use Greenlight\Plugin\CommandResult;
use Greenlight\Reporting\Reporter;

/**
 * Repeats run attempts after watched files or input keys change.
 *
 * @internal
 */
final readonly class WatchRuns
{
    public function __construct(private Console $console) {}

    /** @param non-empty-string|false $workerBin */
    public function run(ParsedArguments $arguments, string $workingDirectory, string|false $workerBin, LoadedConfiguration $configuration, GracefulShutdown $shutdown, ReporterCatalog $reporterCatalog, ReporterOutputPlan $reporterOutputs, Reporter $initialReporter): CommandResult
    {
        if ($workerBin === false) {
            $this->console->error(WatchRunFailed::unavailable()->getMessage(), $arguments->has('no-ansi'));

            return CommandResult::failure();
        }

        $resolved = $configuration->resolved;
        $directories = $configuration->directories;
        $watched = $directories;
        foreach ($resolved->coverage->includePaths ?? [] as $path) {
            $absolute = ConfigurationLoader::absolutePath($path, $workingDirectory);
            if ($absolute !== '' && !\in_array($absolute, $watched, true)) {
                $watched[] = $absolute;
            }
        }
        $additionalPaths = [];
        foreach ($resolved->watch->paths as $path) {
            $absolute = ConfigurationLoader::absolutePath($path, $workingDirectory);
            if ($absolute !== '' && !\in_array($absolute, $additionalPaths, true)) {
                $additionalPaths[] = $absolute;
            }
        }
        $detectLeaks = $arguments->has('detect-leaks');
        $warning = $detectLeaks ? LeakDetector::environmentWarning() : null;
        if ($warning !== null) {
            $this->console->err($this->console->stderrStyle($arguments->has('no-ansi'))->warn($warning) . "\n");
        }
        $nextReporter = $initialReporter;
        $process = new WatchRunProcess($this->console, $shutdown);
        $runOnce = function (array $priorityClasses) use ($arguments, $reporterCatalog, $reporterOutputs, $process, $workerBin, $workingDirectory, $resolved, &$nextReporter): array {
            $priorityClasses = \array_values(\array_filter($priorityClasses, static fn(mixed $class): bool => \is_string($class) && $class !== ''));
            $reporter = $nextReporter ?? new ReporterFactory($this->console)->create($arguments, $reporterCatalog, $reporterOutputs);
            $nextReporter = null;

            return $process->run($workerBin, $arguments, $workingDirectory, $reporter, $priorityClasses, $resolved->order->seed);
        };
        $keys = new StdinKeyInput();
        try {
            $sources = WatchSourceRuntime::fromDefinitions(
                $resolved->execution->plugins,
                [new StatChangeDetector(
                    directories: $watched,
                    additionalPaths: $additionalPaths,
                    matcher: new WatchPathMatcher(
                        $workingDirectory,
                        $resolved->watch->includePatterns,
                        $resolved->watch->excludePatterns,
                    ),
                    maximumFiles: $resolved->watch->maximumFiles,
                )],
            );
            new WatchLoop($sources, new Debouncer($resolved->watch->debounceMilliseconds / 1000), $keys, new SystemWatchClock(), $this->console->out(...), $shutdown)->run($runOnce);
        } catch (ReporterSetupFailed|RunPolicyError|WatchSourceFailed|WatchRunFailed $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return CommandResult::failure();
        } finally {
            $keys->restore();
        }
        $interruptSignal = $shutdown->signal();

        return $interruptSignal === null
            ? CommandResult::success()
            : CommandResult::interrupted($interruptSignal);
    }
}
