<?php

declare(strict_types=1);

namespace Greenlight\Cli\Run;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Configuration\LoadedConfiguration;
use Greenlight\Cli\Discovery\SelectionDiscovery;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Reporting\ReporterCatalog;
use Greenlight\Cli\Reporting\ReporterFactory;
use Greenlight\Cli\Reporting\ReporterOutputPlan;
use Greenlight\Cli\Reporting\ReporterSetupFailed;
use Greenlight\Cli\State\RunState;
use Greenlight\Cli\Watch\Debouncer;
use Greenlight\Cli\Watch\FileChange;
use Greenlight\Cli\Watch\ImpactedTestSelector;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Cli\Watch\StdinKeyInput;
use Greenlight\Cli\Watch\SystemWatchClock;
use Greenlight\Cli\Watch\WatchLoop;
use Greenlight\Cli\Watch\WatchLoopResult;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\ResolvedConfiguration;
use Greenlight\Config\StorageLayout;
use Greenlight\Execution\Worker\LeakDetector;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Internal\Process\GracefulShutdown;
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
    public function run(ParsedArguments $arguments, string $workingDirectory, string|false $workerBin, LoadedConfiguration $configuration, GracefulShutdown $shutdown, ReporterCatalog $reporterCatalog, ReporterOutputPlan $reporterOutputs, Reporter $initialReporter): int
    {
        $impacted = $arguments->has('watch-impacted');
        [$configuration, $coverageMap, $internalCoverageMap] = $this->watchConfiguration(
            $configuration,
            $workingDirectory,
            $impacted,
        );
        $resolved = $configuration->resolved;
        $directories = $configuration->directories;
        $testRoots = $this->resolvedRoots($directories);
        $sourceRoots = [];
        foreach ($resolved->coverage->includePaths ?? [] as $path) {
            $absolute = ConfigurationLoader::absolutePath($path, $workingDirectory);
            $real = ErrorTrap::run(static fn() => \realpath($absolute));
            $source = $real === false ? $absolute : $real;
            if ($source !== '' && !\in_array($source, $sourceRoots, true)) {
                $sourceRoots[] = $source;
            }
        }
        $watched = $impacted
            ? \array_values(\array_unique([$workingDirectory, ...$testRoots, ...$sourceRoots]))
            : \array_values(\array_unique([...$testRoots, ...$sourceRoots]));
        $detectLeaks = $arguments->has('detect-leaks');
        $storage = StorageLayout::resolve(
            $resolved->storage,
            $workingDirectory,
            $resolved->suiteSelection->stateIdentity(),
        );
        $warning = $detectLeaks ? LeakDetector::environmentWarning() : null;
        if ($warning !== null) {
            $this->console->err($this->console->stderrStyle($arguments->has('no-ansi'))->warn($warning) . "\n");
        }
        $nextReporter = $initialReporter;
        $session = new RunSession($this->console, $arguments, $configuration, $workingDirectory, $workerBin, $shutdown, $resolved->selection, RunState::forFile($storage->runStateFile));
        $projectRoot = ErrorTrap::run(static fn() => \realpath($workingDirectory));
        $projectRoot = $projectRoot === false ? $workingDirectory : $projectRoot;
        $selector = null;
        if ($impacted && $coverageMap !== null) {
            $discovery = new SelectionDiscovery($configuration, $workingDirectory);
            $selector = new ImpactedTestSelector(
                $resolved->selection,
                $discovery->plan(...),
                $coverageMap,
                $testRoots,
                $sourceRoots,
                $projectRoot,
                $configuration->file,
            );
        }
        $failedTests = [];
        $mapRunId = null;
        $runOnce = function (array $priorityClasses, array $changes, bool $complete, bool $mapFresh) use ($arguments, $reporterCatalog, $reporterOutputs, $session, $selector, $impacted, &$failedTests, &$mapRunId, &$nextReporter): WatchLoopResult {
            $priorityClasses = \array_values(\array_filter($priorityClasses, static fn(mixed $class): bool => \is_string($class) && $class !== ''));
            $reporter = $nextReporter ?? new ReporterFactory($this->console)->create($arguments, $reporterCatalog, $reporterOutputs);
            $nextReporter = null;
            $selection = null;
            $publishMap = false;

            if ($impacted) {
                if ($complete) {
                    $publishMap = true;
                } elseif (!$mapFresh || !$selector instanceof ImpactedTestSelector) {
                    $publishMap = true;
                    $this->console->out("Impacted watch will run all selected tests. The per-test coverage map is stale.\n");
                } else {
                    $impact = $selector->select($this->fileChanges($changes), $failedTests, $mapRunId);
                    $selection = $impact->selection;
                    $publishMap = $impact->complete;
                    $this->console->out($impact->diagnostic . "\n");
                }
            }

            $attempt = $session->watchAttempt($reporter, $priorityClasses, $selection, $publishMap);
            if ($attempt->completed) {
                $failedTests = $attempt->failedTests;
            }
            if ($attempt->mapPublished) {
                $mapRunId = $attempt->mapRunId;
            }

            return new WatchLoopResult($attempt->failedClasses, $attempt->mapPublished);
        };
        $keys = new StdinKeyInput();
        try {
            new WatchLoop(
                new StatChangeDetector(
                    $watched,
                    $impacted ? \array_values(\array_unique([...$testRoots, ...$sourceRoots])) : [],
                    $impacted ? [$configuration->file] : [],
                ),
                new Debouncer($resolved->watch->debounceMilliseconds / 1000),
                $keys,
                new SystemWatchClock(),
                $this->console->out(...),
                $shutdown,
                $impacted,
            )->run($runOnce);
        } catch (ReporterSetupFailed $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return 1;
        } finally {
            $keys->restore();
            if ($internalCoverageMap) {
                ErrorTrap::run(static fn(): bool => \unlink($coverageMap ?? ''));
            }
        }
        return $shutdown->exitCode() ?? 0;
    }

    /** @return array{LoadedConfiguration, ?non-empty-string, bool} */
    private function watchConfiguration(LoadedConfiguration $configuration, string $workingDirectory, bool $impacted): array
    {
        if (!$impacted) {
            return [$configuration, null, false];
        }

        $resolved = $configuration->resolved;
        $coverage = $resolved->coverage;
        if (!$coverage instanceof CoverageConfiguration) {
            return [$configuration, null, false];
        }

        $internal = $coverage->perTestTarget === null;
        $target = $coverage->perTestTarget;

        if ($target === null) {
            $storage = StorageLayout::resolve(
                $resolved->storage,
                $workingDirectory,
                $resolved->suiteSelection->stateIdentity(),
            );
            $target = \rtrim($storage->temporaryDirectory, '/')
                . '/greenlight-watch-impact-' . (int) \getmypid() . '-' . \bin2hex(\random_bytes(8)) . '.jsonl';
        }

        if ($target === '') {
            throw new \LogicException('The impacted watch coverage-map path is empty.');
        }

        $coverage = new CoverageConfiguration(
            $coverage->includePaths,
            $coverage->driver,
            $coverage->exports,
            $target,
        );
        $watchResolved = new ResolvedConfiguration(
            $resolved->discovery,
            $resolved->suiteSelection,
            $resolved->workers,
            $resolved->execution,
            $resolved->order,
            $resolved->selection,
            $coverage,
            $resolved->watch,
            $resolved->storage,
        );

        $absoluteTarget = ConfigurationLoader::absolutePath($target, $workingDirectory);
        if ($absoluteTarget === '') {
            throw new \LogicException('The impacted watch coverage-map path is empty.');
        }

        return [
            new LoadedConfiguration(
                $watchResolved,
                $configuration->file,
                $configuration->overrides,
                $configuration->directories,
            ),
            $absoluteTarget,
            $internal,
        ];
    }

    /**
     * @param array<mixed> $changes
     * @return list<FileChange>
     */
    private function fileChanges(array $changes): array
    {
        return \array_values(\array_filter(
            $changes,
            static fn(mixed $change): bool => $change instanceof FileChange,
        ));
    }

    /**
     * @param list<string> $roots
     * @return list<string>
     */
    private function resolvedRoots(array $roots): array
    {
        $resolved = [];
        foreach ($roots as $root) {
            $real = ErrorTrap::run(static fn() => \realpath($root));
            $path = $real === false ? $root : $real;

            if ($path !== '' && !\in_array($path, $resolved, true)) {
                $resolved[] = $path;
            }
        }

        return $resolved;
    }
}
