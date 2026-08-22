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
use Greenlight\Cli\State\RunState;
use Greenlight\Cli\Watch\Debouncer;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Cli\Watch\StdinKeyInput;
use Greenlight\Cli\Watch\SystemWatchClock;
use Greenlight\Cli\Watch\WatchLoop;
use Greenlight\Config\StorageLayout;
use Greenlight\Execution\Worker\LeakDetector;
use Greenlight\Internal\Process\GracefulShutdown;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\ReporterProviderError;

/**
 * Repeats run attempts after watched files or input keys change.
 *
 * @internal
 */
final readonly class WatchRuns
{
    public function __construct(private Console $console) {}

    /** @param non-empty-string|false $workerBin */
    public function run(ParsedArguments $arguments, string $workingDirectory, string|false $workerBin, LoadedConfiguration $configuration, GracefulShutdown $shutdown, ReporterCatalog $reporterCatalog, ReporterOutputPlan $reporterOutputs, Reporter $initialReporter): int
    {
        $resolved = $configuration->resolved;
        $directories = $configuration->directories;
        $watched = $directories;
        foreach ($resolved->coverage->includePaths ?? [] as $path) {
            $absolute = ConfigurationLoader::absolutePath($path, $workingDirectory);
            if ($absolute !== '' && !\in_array($absolute, $watched, true)) {
                $watched[] = $absolute;
            }
        }
        $detectLeaks = $arguments->has('detect-leaks');
        $storage = StorageLayout::resolve($resolved->storage, $workingDirectory);
        $warning = $detectLeaks ? LeakDetector::environmentWarning() : null;
        if ($warning !== null) {
            $this->console->err($this->console->stderrStyle($arguments->has('no-ansi'))->warn($warning) . "\n");
        }
        $nextReporter = $initialReporter;
        $session = new RunSession($this->console, $arguments, $configuration, $workingDirectory, $workerBin, $shutdown, $resolved->selection, RunState::forFile($storage->runStateFile));
        $runOnce = function (array $priorityClasses) use ($arguments, $reporterCatalog, $reporterOutputs, $session, &$nextReporter): array {
            $priorityClasses = \array_values(\array_filter($priorityClasses, static fn(mixed $class): bool => \is_string($class) && $class !== ''));
            $reporter = $nextReporter ?? new ReporterFactory($this->console)->create($arguments, $reporterCatalog, $reporterOutputs);
            $nextReporter = null;

            return $session->watchAttempt($reporter, $priorityClasses);
        };
        $keys = new StdinKeyInput();
        try {
            new WatchLoop(new StatChangeDetector($watched), new Debouncer($resolved->watch->debounceMilliseconds / 1000), $keys, new SystemWatchClock(), $this->console->out(...), $shutdown)->run($runOnce);
        } catch (ReporterProviderError $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return 1;
        } finally {
            $keys->restore();
        }
        return $shutdown->exitCode() ?? 0;
    }
}
