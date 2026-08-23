<?php

declare(strict_types=1);

namespace Greenlight\Cli\Run;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Cli\Configuration\LoadedConfiguration;
use Greenlight\Cli\Coverage\CoverageSession;
use Greenlight\Cli\Coverage\CoverageSettingsResolver;
use Greenlight\Cli\Coverage\CoverageWriter;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Reporting\FailedTestsTap;
use Greenlight\Cli\Reporting\ReporterSink;
use Greenlight\Cli\State\RunState;
use Greenlight\Cli\Watch\ClassFailureTap;
use Greenlight\Cli\WorkerCapacity\CpuCores;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\StorageLayout;
use Greenlight\Config\WorkerConfiguration;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Ignore\IgnoreFilter;
use Greenlight\Coverage\Relay\SubprocessCoverage;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Event\EventSink;
use Greenlight\Execution\Adapter\InProcessExecution;
use Greenlight\Execution\Adapter\ProcessPoolExecution;
use Greenlight\Execution\ExecutionAdapter;
use Greenlight\Execution\ExecutionFailed;
use Greenlight\Execution\RunCoordinator;
use Greenlight\Execution\RunResult;
use Greenlight\IntegrationFixture\IntegrationFixtureError;
use Greenlight\Internal\Process\GracefulShutdown;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Reporting\SummaryFormat;
use Greenlight\Reporting\Ticking;
use Greenlight\Test\TestSelection;

/**
 * Runs one normal or watch attempt with one stable command configuration.
 *
 * @internal
 */
final readonly class RunSession
{
    /** @param non-empty-string|false $workerBin */
    public function __construct(
        private Console $console,
        private ParsedArguments $arguments,
        private LoadedConfiguration $configuration,
        private string $workingDirectory,
        private string|false $workerBin,
        private GracefulShutdown $shutdown,
        private TestSelection $selection,
        private RunState $state,
        private bool $coverageOutputOnStderr = false,
    ) {}

    /**
     * @param list<non-empty-string> $priorityClasses
     * @param array<string, float> $classSeconds
     * @throws CoverageError
     * @throws ReportGenerationFailed
     */
    public function runAttempt(Reporter $reporter, array $priorityClasses, array $classSeconds): RunAttemptResult
    {
        $failedTap = new FailedTestsTap(new ReporterSink($reporter));
        $exitCode = $this->execute($reporter, $failedTap, $priorityClasses, $classSeconds);

        return new RunAttemptResult($exitCode, $failedTap->failedTests(), $failedTap->classSeconds());
    }

    /**
     * @param list<non-empty-string> $priorityClasses
     * @return list<non-empty-string>
     * @throws ReportGenerationFailed
     */
    public function watchAttempt(Reporter $reporter, array $priorityClasses): array
    {
        $tap = new ClassFailureTap($failedTap = new FailedTestsTap(new ReporterSink($reporter)));
        $classSeconds = $this->configuration->resolved->order->isRandomized() ? [] : $this->state->classSeconds();
        $workers = $this->configuration->resolved->workers->count->fixed ?? CpuCores::count();
        $coverageSettings = CoverageSettingsResolver::resolve($this->configuration->resolved->coverage, $this->workingDirectory);

        try {
            $this->coordinate($reporter, $tap, $priorityClasses, $classSeconds, $workers, $coverageSettings);
        } catch (AttachmentError|DiscoveryError|ExecutionFailed|IntegrationFixtureError $error) {
            $reporter->finish();
            $this->console->error($error->getMessage(), $this->arguments->has('no-ansi'));

            return $priorityClasses;
        }

        $reporter->finish();
        $this->persist($failedTap->failedTests(), $failedTap->classSeconds());

        return $tap->failedClasses();
    }

    /**
     * @param list<non-empty-string> $failedTests
     * @param array<non-empty-string, float> $classSeconds
     */
    public function persist(array $failedTests, array $classSeconds): void
    {
        if (!$this->state->record($failedTests, $classSeconds)) {
            $this->console->err("Greenlight did not save run state. On the next run, --failed and longest-first scheduling have no prior data.\n");
        }
    }

    /**
     * @param list<non-empty-string> $priorityClasses
     * @param array<string, float> $classSeconds
     * @throws CoverageError
     * @throws ReportGenerationFailed
     */
    private function execute(Reporter $reporter, FailedTestsTap $failedTap, array $priorityClasses, array $classSeconds): int
    {
        $resolved = $this->configuration->resolved;
        $workers = $resolved->workers->count->fixed ?? CpuCores::count();
        $coverageSettings = CoverageSettingsResolver::resolve($resolved->coverage, $this->workingDirectory);
        $coverageSession = CoverageSession::open(
            $coverageSettings,
            $workers !== 1 && $this->workerBin !== false && !SubprocessCoverage::requested(),
            StorageLayout::resolve($resolved->storage, $this->workingDirectory)->temporaryDirectory,
        );

        try {
            try {
                $run = $this->coordinate($reporter, $failedTap, $priorityClasses, $classSeconds, $workers, $coverageSettings);
            } catch (AttachmentError|DiscoveryError|ExecutionFailed|IntegrationFixtureError $error) {
                $reporter->finish();
                $this->console->error($error->getMessage(), $this->arguments->has('no-ansi'));
                $interruptExit = $this->shutdown->exitCode();
                if ($interruptExit !== null) {
                    $this->console->err("Interrupted. Integration fixture teardown was attempted before exit.\n");
                }

                return $interruptExit ?? 1;
            }
            $coverage = $coverageSession->finish($run->coverage);
        } finally {
            $coverageSession->close();
        }

        if ($coverage instanceof CoverageMap) {
            $coverage = new IgnoreFilter()->apply($coverage);
        }
        $reporter->finish();
        $this->persist($failedTap->failedTests(), $failedTap->classSeconds());
        $interruptExit = $this->shutdown->exitCode();
        if ($interruptExit !== null) {
            $this->console->err("Interrupted. The summary includes only tests that finished before shutdown.\n");

            return $interruptExit;
        }
        if ($run->plannedTests === 0) {
            $this->console->err("Greenlight found no tests. Check the configuration, test paths, and filters.\n");

            return 1;
        }
        $coverageConfig = $resolved->coverage;
        if ($coverageConfig instanceof CoverageConfiguration) {
            if (!$coverage instanceof CoverageMap) {
                if ($coverageConfig->requiresCoverageResult()) {
                    $this->console->err("Coverage is required, but no worker collected it. Install pcov or enable Xdebug with coverage mode.\n");

                    return 1;
                }

                $this->console->err("No worker collected the requested coverage. Install pcov or enable Xdebug with coverage mode.\n");
            } elseif (!new CoverageWriter($this->console, $this->coverageOutputOnStderr)->write(
                $coverageConfig,
                $coverage,
                $this->workingDirectory,
                $this->coverageOutputOnStderr
                    ? $this->console->stderrStyle($this->arguments->has('no-ansi'))
                    : $this->console->stdoutStyle($this->arguments->has('no-ansi')),
            )) {
                return 1;
            }
        }
        if ($run->leaks !== []) {
            $this->console->err(SummaryFormat::leaks($run->leaks, $this->console->stderrStyle($this->arguments->has('no-ansi'))));

            return 1;
        }

        return $run->summary->isSuccessful() ? 0 : 1;
    }

    /**
     * @param list<non-empty-string> $priorityClasses
     * @param array<string, float> $classSeconds
     * @param positive-int $workers
     * @throws AttachmentError
     * @throws DiscoveryError
     * @throws IntegrationFixtureError
     * @throws ExecutionFailed
     * @throws ReportGenerationFailed
     */
    private function coordinate(Reporter $reporter, EventSink $sink, array $priorityClasses, array $classSeconds, int $workers, ?CoverageSettings $coverageSettings): RunResult
    {
        $resolved = $this->configuration->resolved;

        return new RunCoordinator($this->workingDirectory)->run(
            $resolved,
            $this->selection,
            $this->configuration->directories,
            $sink,
            $this->adapter($resolved->workers, $workers, $coverageSettings, $reporter),
            $priorityClasses,
            $classSeconds,
        );
    }

    /** @param positive-int $workers */
    private function adapter(WorkerConfiguration $workerConfiguration, int $workers, ?CoverageSettings $coverageSettings, Reporter $reporter): ExecutionAdapter
    {
        if ($workers === 1 || $this->workerBin === false) {
            return new InProcessExecution($coverageSettings, $this->arguments->has('detect-leaks'), $this->shutdown);
        }

        return new ProcessPoolExecution(
            [\PHP_BINARY, $this->workerBin],
            $this->workingDirectory,
            $workers,
            $workerConfiguration,
            $coverageSettings,
            $this->configuration->file,
            $this->arguments->has('detect-leaks'),
            $this->shutdown,
            $reporter instanceof Ticking ? $reporter : null,
        );
    }
}
