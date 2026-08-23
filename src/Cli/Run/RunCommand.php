<?php

declare(strict_types=1);

namespace Greenlight\Cli\Run;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Configuration\PlanFormatter;
use Greenlight\Cli\Configuration\RepeatConfiguration;
use Greenlight\Cli\Discovery\SelectionDiscovery;
use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Output\Terminal;
use Greenlight\Cli\Output\TerminalCapabilities;
use Greenlight\Cli\Reporting\ReporterFactory;
use Greenlight\Cli\Reporting\ReporterOutputPlan;
use Greenlight\Cli\Reporting\ReporterSetupFailed;
use Greenlight\Cli\Signal\SignalHandlers;
use Greenlight\Cli\State\RunState;
use Greenlight\Cli\WorkerCapacity\CpuCores;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\StorageLayout;
use Greenlight\Coverage\CoverageError;
use Greenlight\Execution\Worker\LeakDetector;
use Greenlight\Internal\Process\GracefulShutdown;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Reporting\Style;

/**
 * Orchestrates one ordinary run command and its repeat policy.
 *
 * Uses exit code 0 for success. Uses 1 for a test or run failure. Uses 64 for
 * invalid command-line use.
 *
 * @internal
 */
final readonly class RunCommand
{
    private const int EXIT_OK = 0;
    private const int EXIT_FAILURE = 1;
    private const int EXIT_USAGE = 64;

    /** @var resource */
    private mixed $stderr;

    /** @var \Closure(string): void */
    private \Closure $out;

    /** @var \Closure(string): void */
    private \Closure $err;

    /** @param non-empty-string $version */
    public function __construct(private Console $console, private string $version)
    {
        $this->stderr = $this->console->stderr();
        $this->out = $this->console->out(...);
        $this->err = $this->console->err(...);
    }

    /**
     * @throws CoverageError
     * @throws ReportGenerationFailed
     */
    public function run(ParsedArguments $arguments, string $workingDirectory, ?string $binPath): int
    {
        return $this->runCommand($arguments, $workingDirectory, $binPath);
    }

    /**
     * @throws CoverageError
     * @throws ReportGenerationFailed
     */
    private function runCommand(ParsedArguments $arguments, string $workingDirectory, ?string $binPath = null): int
    {
        try {
            $configuration = new ConfigurationLoader()->load($arguments, $workingDirectory);
        } catch (CliError $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_USAGE;
        } catch (ConfigFileError|InvalidConfiguration $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_FAILURE;
        }
        $resolved = $configuration->resolved;
        $configFile = $configuration->file;
        $overrides = $configuration->overrides;

        if ($arguments->has('watch') && ($overrides->repeat->count !== null || $overrides->repeat->untilFailure)) {
            $this->printError('Do not use --watch with --repeat or --repeat-until-failure.', $arguments->has('no-ansi'));

            return self::EXIT_USAGE;
        }

        if ($arguments->has('watch') && $resolved->coverage?->perTestTarget !== null) {
            $this->printError('Per-test coverage is not available in watch mode.', $arguments->has('no-ansi'));

            return self::EXIT_USAGE;
        }

        if ($arguments->has('dry-run')) {
            ($this->out)(PlanFormatter::format($resolved, $configFile, $workingDirectory));

            return self::EXIT_OK;
        }

        $this->warnWhenExcludePathsMatchNothing(new SelectionDiscovery($configuration, $workingDirectory), $arguments->has('no-ansi'));

        $workers = $resolved->workers->count->fixed ?? CpuCores::count();
        $workerBin = $this->workerBinPath($binPath);
        $reporterFactory = new ReporterFactory($this->console);

        try {
            $reporterCatalog = $reporterFactory->catalog(
                $arguments,
                $resolved->execution->plugins,
                $resolved->order->seed,
                $configFile,
                $workingDirectory,
                workerFallback: $workers > 1 && $workerBin === false,
                version: $this->version,
            );
            $this->assertRepeatOutputsAreCompatible($arguments, $overrides->repeat, $resolved->coverage);
            $reporterOutputs = $reporterFactory->outputs($arguments, $reporterCatalog, $workingDirectory);
        } catch (CliError $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_USAGE;
        } catch (ReporterSetupFailed $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_FAILURE;
        }

        try {
            $reporter = $reporterFactory->create($arguments, $reporterCatalog, $reporterOutputs);
        } catch (CliError $error) {
            $reporterOutputs->close();
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_USAGE;
        } catch (ReporterSetupFailed $error) {
            $reporterOutputs->close();
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_FAILURE;
        }

        try {
            $shutdown = new GracefulShutdown();
            SignalHandlers::install($shutdown);

            if ($arguments->has('watch')) {
                return new WatchRuns($this->console)->run($arguments, $workingDirectory, $workerBin, $configuration, $shutdown, $reporterCatalog, $reporterOutputs, $reporter);
            }

            $storage = StorageLayout::resolve(
                $resolved->storage,
                $workingDirectory,
                $resolved->suiteSelection->stateIdentity(),
            );
            $state = RunState::forFile($storage->runStateFile);
            $previousFailures = $state->failedTests();

            if ($arguments->has('failed')) {
                if ($previousFailures === null) {
                    $this->printError('--failed requires state from a previous run. Run Greenlight once without --failed.', $arguments->has('no-ansi'));

                    return self::EXIT_USAGE;
                }

                if ($previousFailures === []) {
                    ($this->out)("No tests failed in the previous run. There are no tests to run again.\n");

                    return self::EXIT_OK;
                }

                $selection = $resolved->selection->withExactIds($previousFailures);
            } else {
                $selection = $resolved->selection;
            }

            $priorityClasses = [];
            $priorityClassSet = [];

            if (!$resolved->order->isRandomized() && \is_array($previousFailures)) {
                foreach ($previousFailures as $id) {
                    $class = \strstr($id, '::', true);

                    if (\is_string($class) && $class !== '' && !isset($priorityClassSet[$class])) {
                        $priorityClassSet[$class] = true;
                        $priorityClasses[] = $class;
                    }
                }
            }

            $classSeconds = $resolved->order->isRandomized() ? [] : $state->classSeconds();
            $this->warnWhenLeakDetectionIsUnreliable($arguments->has('detect-leaks'), $arguments->has('no-ansi'));
            $session = new RunSession($this->console, $arguments, $configuration, $workingDirectory, $workerBin, $shutdown, $selection, $state);

            // --repeat=1 specifies one standard run. Show the loop, banners, and
            // summary only when more than one iteration is possible.
            if (($overrides->repeat->count === null || $overrides->repeat->count === 1) && !$overrides->repeat->untilFailure) {
                return $session->runAttempt($reporter, $priorityClasses, $classSeconds)->exitCode;
            }

            // Without an explicit --repeat, --repeat-until-failure has a limit of
            // 100 iterations.
            $limit = $overrides->repeat->count ?? 100;
            $bounded = $overrides->repeat->count !== null;
            $failedIterations = [];
            $failedTests = [];
            $failedTestSet = [];
            $lastClassSeconds = [];
            $repeatOutput = $reporterOutputs->writesReporterToStandardOutput('jsonl')
                ? $this->err
                : $this->out;

            for ($iteration = 1; $iteration <= $limit; $iteration++) {
                $repeatOutput($bounded
                    ? \sprintf("Repeat: iteration %d of %d\n", $iteration, $limit)
                    : \sprintf("Repeat: iteration %d of at most %d\n", $iteration, $limit));

                if ($iteration > 1) {
                    try {
                        $reporter = $reporterFactory->create($arguments, $reporterCatalog, $reporterOutputs);
                    } catch (CliError $error) {
                        $this->printError($error->getMessage(), $arguments->has('no-ansi'));

                        return self::EXIT_USAGE;
                    } catch (ReporterSetupFailed $error) {
                        $this->printError($error->getMessage(), $arguments->has('no-ansi'));

                        return self::EXIT_FAILURE;
                    }
                }

                $attempt = $session->runAttempt($reporter, $priorityClasses, $classSeconds);

                foreach ($attempt->failedTests as $id) {
                    if (!isset($failedTestSet[$id])) {
                        $failedTestSet[$id] = true;
                        $failedTests[] = $id;
                    }
                }

                $lastClassSeconds = $attempt->classSeconds;

                $interruptExit = $shutdown->exitCode();

                if ($interruptExit !== null) {
                    return $interruptExit;
                }

                if ($attempt->exitCode !== self::EXIT_OK) {
                    $failedIterations[] = $iteration;

                    if ($overrides->repeat->untilFailure) {
                        break;
                    }
                }
            }

            if ($failedIterations === []) {
                $repeatOutput(\sprintf("Repeat: %d iterations, all passed\n", $limit));

                return self::EXIT_OK;
            }

            // Record each test that fails in an iteration. Thus, a later --failed
            // run includes it even if it passes in another iteration.
            $session->persist($failedTests, $lastClassSeconds);

            $repeatOutput(\sprintf("Repeat: failed iterations: %s\n", \implode(', ', $failedIterations)));

            return self::EXIT_FAILURE;
        } finally {
            $reporterOutputs->close();
        }
    }

    private function warnWhenLeakDetectionIsUnreliable(bool $detectLeaks, bool $noAnsiFlag): void
    {
        if (!$detectLeaks) {
            return;
        }

        $warning = LeakDetector::environmentWarning();

        if ($warning !== null) {
            ($this->err)($this->stderrStyle($noAnsiFlag)->warn($warning) . "\n");
        }
    }

    private function warnWhenExcludePathsMatchNothing(SelectionDiscovery $discovery, bool $noAnsiFlag): void
    {
        foreach ($discovery->unmatchedExcludePathWarnings() as $warning) {
            ($this->err)($this->stderrStyle($noAnsiFlag)->warn($warning) . "\n");
        }
    }

    private function printError(string $message, bool $noAnsiFlag): void
    {
        ($this->err)($this->stderrStyle($noAnsiFlag)->error('greenlight:') . ' ' . $message . "\n");
    }

    private function stderrStyle(bool $noAnsiFlag): Style
    {
        $capabilities = TerminalCapabilities::detect(
            Terminal::isTty($this->stderr),
            ['CI' => \getenv('CI'), 'NO_COLOR' => \getenv('NO_COLOR')],
            $noAnsiFlag,
        );

        return new Style($capabilities->color);
    }

    /** @throws CliError */
    private function assertRepeatOutputsAreCompatible(ParsedArguments $arguments, RepeatConfiguration $repeat, ?CoverageConfiguration $coverage): void
    {
        if (!$repeat->usesRepeatMode()) {
            return;
        }

        $outputs = [];
        if (ReporterOutputPlan::selects($arguments->values('reporter'), 'junit')) {
            $outputs[] = 'JUnit output';
        }
        if ($coverage instanceof CoverageConfiguration) {
            $outputs[] = 'enabled coverage';
        }
        if ($outputs !== []) {
            throw CliError::repeatWithSingleRunOutput($outputs);
        }
    }

    /** @return non-empty-string|false */
    private function workerBinPath(?string $binPath): string|false
    {
        return WorkerExecutable::resolve($binPath);
    }

}
