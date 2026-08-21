<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Cli\Watch\ClassFailureTap;
use Greenlight\Cli\Watch\Debouncer;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Cli\Watch\StdinKeyInput;
use Greenlight\Cli\Watch\SystemWatchClock;
use Greenlight\Cli\Watch\WatchLoop;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Config\Configuration;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\StorageLayout;
use Greenlight\Config\SuiteConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\AtomicFile;
use Greenlight\Core\AtomicFileError;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Event\EventTags;
use Greenlight\Core\GracefulShutdown;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireError;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Diff\BaselineDiff;
use Greenlight\Coverage\Export\CloverExporter;
use Greenlight\Coverage\Export\CoberturaExporter;
use Greenlight\Coverage\Export\CoverageExporter;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Coverage\Export\LcovExporter;
use Greenlight\Coverage\Ignore\IgnoreFilter;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\PhpStan\IdeHelper;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\PhpStan\MatcherMapError;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\ReporterProvider;
use Greenlight\Reporting\CompositeReporter;
use Greenlight\Reporting\GithubReporter;
use Greenlight\Reporting\JsonLinesReporter;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Reporting\Output\Output;
use Greenlight\Reporting\Output\StreamOutput;
use Greenlight\Reporting\PlainReporter;
use Greenlight\Reporting\ProfileAggregator;
use Greenlight\Reporting\ProfileReporter;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\ReporterDefinition;
use Greenlight\Reporting\ReporterProviderError;
use Greenlight\Reporting\ReportingError;
use Greenlight\Reporting\RunHeader;
use Greenlight\Reporting\Style;
use Greenlight\Reporting\SummaryFormat;
use Greenlight\Reporting\TeamCityReporter;
use Greenlight\Reporting\Ticking;
use Greenlight\Reporting\TtyReporter;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\CpuCores;
use Greenlight\Runner\Execution\ExecutionAdapter;
use Greenlight\Runner\Execution\InProcessExecution;
use Greenlight\Runner\Execution\ProcessPoolExecution;
use Greenlight\Runner\Integration\IntegrationFixtureError;
use Greenlight\Runner\PlanShard;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\RunCoordinator;
use Greenlight\Runner\RunResult;
use Greenlight\Runner\SelectionFilter;
use Greenlight\Runner\SubprocessCoverage;
use Greenlight\Runner\Worker\EventSink;
use Greenlight\Runner\Worker\LeakDetector;
use Greenlight\Runner\Worker\WorkerProcess;

/**
 * Uses exit code 0 for success. Uses 1 for a test or run failure. Uses 64 for
 * invalid command-line use.
 *
 * @internal
 */
final readonly class Application
{
    public const string VERSION = 'dev-main';

    private const int EXIT_OK = 0;
    private const int EXIT_FAILURE = 1;
    private const int EXIT_USAGE = 64;

    /** @var list<non-empty-string> */
    private const array WORKER_RUNTIME_FUNCTIONS = [
        'proc_open',
        'proc_get_status',
        'proc_terminate',
        'proc_close',
        'stream_socket_server',
        'stream_socket_get_name',
        'stream_socket_accept',
        'stream_socket_client',
        'stream_select',
        'stream_set_blocking',
    ];

    private const string HELP = <<<'HELP'
        Greenlight

        Usage:
          greenlight [command] [options]

        Commands:
          run            Find and run tests (default)
          list-tests     List each found test ID, one per line
          coverage:diff  Compare two coverage JSON exports. Fail if total coverage
                         decreases or a line becomes newly uncovered.
          profile:report Create a run profile from a saved JSONL stream (--input)
          ide-helper     Write the IDE autocomplete helper for extension matchers
                         (--output, default _greenlight_ide_helper.php)
          completion     Print a shell completion script for bash, zsh, or fish
                         to standard output, for example:
                         source <(greenlight completion bash)

        Options:
          --config=<path>    Use this configuration file instead of ./greenlight.php
          --workers=<n|auto> Set the worker process count
          --resource-limit=<name>=<n>
                             Set a named resource limit. You can repeat this option.
          --bail[=<n>]       Stop after <n> failures (default 1)
          --group=<name>     Run only this group. You can repeat this option.
          --filter=<pattern> Run only tests with a matching test ID. Use a
                             substring or a full match with * wildcards.
                             You can repeat this option.
          --test-id=<id>     Run only this exact test ID. You can repeat this option.
          --exclude-group=<name>     Skip tests in this group. You can repeat this option.
          --exclude-class=<pattern>  Skip classes that match this pattern.
                             Matching is case-sensitive. Use a substring or * wildcards.
                             You can repeat this option.
          --exclude-method=<pattern> Skip methods that match this pattern.
                             Matching is case-sensitive. Use a substring or * wildcards.
                             You can repeat this option.
          --exclude-path=<prefix>    Skip test files under this path prefix.
                             Greenlight resolves relative prefixes against the
                             working directory. You can repeat this option.
          --failed           Run only tests that failed or had an error in the previous run
          --list-tests       Print the selected test IDs. Do not run the tests.
          --list-groups      Print each selected group and its test count
          --list-suites      Print the configured suites
          --repeat=<n>       Run the selected tests n times in separate runs.
                             A failed iteration fails the command.
          --repeat-until-failure  Repeat until an iteration fails, up to
                             --repeat times (default at most 100)
          --shard=<n>/<m>    Run shard n of m. Shards are disjoint and contain
                             whole classes. They are stable across machines and
                             need no coordination.
          --seed=<n>         Randomize class order with this seed
          --reporter=<name>  Select a built-in or configured reporter name.
                             Built-ins: tty, plain, junit, jsonl, github, teamcity.
                             You can repeat this option.
          --artifacts-dir=<path> Persistent directory for retained test attachments
          --watch            Run selected tests at startup and after file changes.
                             Enter reruns them. q quits with exit code 0.
          --detect-leaks     Verify collection of each test instance. Leaks fail the run.
          --verbose          Print a permanent line per completed class in
                             interactive output
          --ansi             Enable colors in append-only reporter output.
          --no-ansi          Disable colors and the live progress window.
                             Use plain append-only output.
          --fail-on-deprecation  Fail passed tests that captured a deprecation
          --fail-on-notice   Fail passed tests that captured a notice
          --fail-on-risky    Fail passed tests that verified no expectations
          --profile          Add a run profile after the summary. It contains worker
                             utilization, boot latency, makespan spread, and slow classes.
                             It also extends the slow-test list.
          --dry-run          Print a run-settings summary without test discovery
                             or execution.
          -h, --help         Show this help
          -V, --version      Show the version

        HELP;

    /**
     * @param resource $stdout
     * @param resource $stderr
     * @param \Closure(string): void $out
     * @param \Closure(string): void $err
     */
    private function __construct(
        private mixed $stdout,
        private mixed $stderr,
        private \Closure $out,
        private \Closure $err,
    ) {}

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public static function forStreams($stdout = null, $stderr = null): self
    {
        $stdout ??= \STDOUT;
        $stderr ??= \STDERR;
        $out = new StreamOutput($stdout);
        $err = new StreamOutput($stderr);

        return new self(
            $stdout,
            $stderr,
            static function (string $text) use ($out): void {
                $out->write($text);
            },
            static function (string $text) use ($err): void {
                $err->write($text);
            },
        );
    }

    /**
     * @param list<string> $argv the arguments after the script name
     * @throws CoverageError
     * @throws ProtocolError
     * @throws ReportingError
     * @throws WireError
     */
    public function run(array $argv, string $workingDirectory, ?string $binPath = null): int
    {
        // The orchestrator starts this internal worker entry. It does not use
        // the normal parser. No documentation or compatibility guarantee
        // applies to it.
        if (($argv[0] ?? null) === '__worker') {
            if (\count($argv) !== 4 || $argv[1] === '' || $argv[2] === '' || $argv[3] === '') {
                ($this->err)("__worker requires <address> <workerId> <token>.\n");

                return self::EXIT_USAGE;
            }

            return new WorkerProcess()->run($argv[1], $argv[2], $argv[3]);
        }

        // A run with coverage exports the relay variables to each child
        // process. A CLI process that inherits them reports its coverage
        // through the shared directory.
        $dump = SubprocessCoverage::begin();

        if (!$dump instanceof SubprocessCoverage) {
            return $this->dispatch($argv, $workingDirectory, $binPath);
        }

        try {
            return $this->dispatch($argv, $workingDirectory, $binPath);
        } finally {
            $dump->write();
        }
    }

    /**
     * @param list<string> $argv the arguments after the script name
     * @throws CoverageError
     * @throws ReportingError
     * @throws WireError
     */
    private function dispatch(array $argv, string $workingDirectory, ?string $binPath): int
    {
        // Process this command before the parse operation. The parser does not
        // model the shell name as a positional argument.
        if (($argv[0] ?? null) === 'completion') {
            return $this->completionCommand(\array_slice($argv, 1));
        }

        try {
            $arguments = $this->parser()->parse($argv);
        } catch (CliError $error) {
            // The parse operation failed. Thus, only raw argv identifies flags.
            $this->printError($error->getMessage(), \in_array('--no-ansi', $argv, true));

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

        if ($command === 'coverage:diff') {
            return $this->coverageDiffCommand($arguments, $workingDirectory);
        }

        if ($command === 'profile:report') {
            return $this->profileReportCommand($arguments, $workingDirectory);
        }

        if ($command === 'ide-helper') {
            return $this->ideHelperCommand($arguments, $workingDirectory);
        }

        $this->printError(\sprintf("Unknown command '%s'. Use greenlight --help to list commands.", $command), $arguments->has('no-ansi'));

        return self::EXIT_USAGE;
    }

    /**
     * @throws CoverageError
     * @throws ReportingError
     * @throws WireError
     */
    private function runCommand(ParsedArguments $arguments, string $workingDirectory, ?string $binPath = null): int
    {
        try {
            [$resolved, $configFile] = $this->loadConfiguration($arguments, $workingDirectory);
            $overrides = CliOverrides::fromArguments($arguments);
        } catch (CliError $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_USAGE;
        } catch (ConfigFileError|InvalidConfiguration $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_FAILURE;
        }

        if ($arguments->has('watch') && ($overrides->repeat !== null || $overrides->repeatUntilFailure)) {
            $this->printError('Do not use --watch with --repeat or --repeat-until-failure.', $arguments->has('no-ansi'));

            return self::EXIT_USAGE;
        }

        if ($arguments->has('dry-run')) {
            ($this->out)(PlanFormatter::format($resolved, $configFile, $workingDirectory));

            return self::EXIT_OK;
        }

        if ($arguments->has('list-suites')) {
            return $this->printSuiteList($resolved);
        }

        $this->warnWhenExcludePathsMatchNothing($resolved, $workingDirectory, $arguments->has('no-ansi'));

        if ($arguments->has('list-tests') || $arguments->has('list-groups')) {
            try {
                $plan = $this->discoverSelection($resolved, $workingDirectory);
            } catch (DiscoveryError $error) {
                $this->printError($error->getMessage(), $arguments->has('no-ansi'));

                return self::EXIT_FAILURE;
            }

            return $arguments->has('list-tests') ? $this->printTestList($plan) : $this->printGroupList($plan);
        }

        $workers = $resolved->workers->fixed ?? CpuCores::count();
        $workerBin = $this->workerBinPath($binPath);

        try {
            $reporterCatalog = $this->reporterCatalog(
                $arguments,
                $resolved->plugins,
                $resolved->randomSeed,
                $configFile,
                $workingDirectory,
                workerFallback: $workers > 1 && $workerBin === false,
            );
            $reporter = $this->buildReporter($arguments, $reporterCatalog);
        } catch (CliError $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_USAGE;
        } catch (ReporterProviderError $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_FAILURE;
        }

        $shutdown = new GracefulShutdown();
        SignalHandlers::install($shutdown);

        if ($arguments->has('watch')) {
            return $this->watchCommand($arguments, $workingDirectory, $workerBin, $resolved, $configFile, $shutdown, $reporterCatalog, $reporter);
        }

        $storage = StorageLayout::resolve($resolved->storage, $workingDirectory);
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

            $resolved = $resolved->withOnlyTests($previousFailures);
        }

        $priorityClasses = [];
        $priorityClassSet = [];

        if (!$resolved->randomizeOrder && \is_array($previousFailures)) {
            foreach ($previousFailures as $id) {
                $class = \strstr($id, '::', true);

                if (\is_string($class) && $class !== '' && !isset($priorityClassSet[$class])) {
                    $priorityClassSet[$class] = true;
                    $priorityClasses[] = $class;
                }
            }
        }

        $classSeconds = $resolved->randomizeOrder ? [] : $state->classSeconds();
        $this->warnWhenLeakDetectionIsUnreliable($arguments->has('detect-leaks'), $arguments->has('no-ansi'));

        // --repeat=1 specifies one standard run. Show the loop, banners, and
        // summary only when more than one iteration is possible.
        if (($overrides->repeat === null || $overrides->repeat === 1) && !$overrides->repeatUntilFailure) {
            $failedTap = new FailedTestsTap(new ReporterSink($reporter));

            return $this->executeRun($arguments, $resolved, $configFile, $workingDirectory, $workerBin, $shutdown, $priorityClasses, $classSeconds, $reporter, $failedTap, $state);
        }

        // Without an explicit --repeat, --repeat-until-failure has a limit of
        // 100 iterations.
        $limit = $overrides->repeat ?? 100;
        $bounded = $overrides->repeat !== null;
        $failedIterations = [];
        $failedTests = [];
        $failedTestSet = [];
        $lastClassSeconds = [];

        for ($iteration = 1; $iteration <= $limit; $iteration++) {
            ($this->out)($bounded
                ? \sprintf("Repeat: iteration %d of %d\n", $iteration, $limit)
                : \sprintf("Repeat: iteration %d of at most %d\n", $iteration, $limit));

            if ($iteration > 1) {
                try {
                    $reporter = $this->buildReporter($arguments, $reporterCatalog);
                } catch (CliError $error) {
                    $this->printError($error->getMessage(), $arguments->has('no-ansi'));

                    return self::EXIT_USAGE;
                } catch (ReporterProviderError $error) {
                    $this->printError($error->getMessage(), $arguments->has('no-ansi'));

                    return self::EXIT_FAILURE;
                }
            }

            $failedTap = new FailedTestsTap(new ReporterSink($reporter));
            $exit = $this->executeRun($arguments, $resolved, $configFile, $workingDirectory, $workerBin, $shutdown, $priorityClasses, $classSeconds, $reporter, $failedTap, $state);

            foreach ($failedTap->failedTests() as $id) {
                if (!isset($failedTestSet[$id])) {
                    $failedTestSet[$id] = true;
                    $failedTests[] = $id;
                }
            }

            $lastClassSeconds = $failedTap->classSeconds();

            $interruptExit = $shutdown->exitCode();

            if ($interruptExit !== null) {
                return $interruptExit;
            }

            if ($exit !== self::EXIT_OK) {
                $failedIterations[] = $iteration;

                if ($overrides->repeatUntilFailure) {
                    break;
                }
            }
        }

        if ($failedIterations === []) {
            ($this->out)(\sprintf("Repeat: %d iterations, all passed\n", $limit));

            return self::EXIT_OK;
        }

        // Record each test that fails in an iteration. Thus, a later --failed
        // run includes it even if it passes in another iteration.
        $this->persistRunState($state, $failedTests, $lastClassSeconds);

        ($this->out)(\sprintf("Repeat: failed iterations: %s\n", \implode(', ', $failedIterations)));

        return self::EXIT_FAILURE;
    }

    /**
     * Use a new reporter. This method calls finish() exactly one time.
     *
     * @param non-empty-string|false $workerBin
     * @param list<non-empty-string> $priorityClasses
     * @param array<string, float> $classSeconds
     * @throws CoverageError
     * @throws ReportingError
     * @throws WireError
     */
    private function executeRun(
        ParsedArguments $arguments,
        Configuration $resolved,
        string $configFile,
        string $workingDirectory,
        string|false $workerBin,
        GracefulShutdown $shutdown,
        array $priorityClasses,
        array $classSeconds,
        Reporter $reporter,
        FailedTestsTap $failedTap,
        RunState $state,
    ): int {
        $workers = $resolved->workers->fixed ?? CpuCores::count();
        $coverageSettings = CoverageSettingsResolver::resolve($resolved->coverage, $workingDirectory);
        $detectLeaks = $arguments->has('detect-leaks');

        // The worker-pool path collects orchestrator coverage unless this
        // process inherits relay variables. A second driver period closes
        // the inherited process period too early.
        $coverageSession = CoverageSession::open(
            $coverageSettings,
            $workers !== 1 && $workerBin !== false && !SubprocessCoverage::requested(),
            StorageLayout::resolve($resolved->storage, $workingDirectory)->temporaryDirectory,
        );

        try {
            try {
                $run = $this->coordinateRun(
                    $resolved,
                    $this->directories($resolved, $workingDirectory),
                    $failedTap,
                    $workers,
                    $workerBin,
                    $workingDirectory,
                    $coverageSettings,
                    $configFile,
                    $detectLeaks,
                    $priorityClasses,
                    $classSeconds,
                    $shutdown,
                    $reporter,
                );
            } catch (AttachmentError|DiscoveryError|IntegrationFixtureError|ProtocolError $error) {
                $reporter->finish();
                $this->printError($error->getMessage(), $arguments->has('no-ansi'));

                $interruptExit = $shutdown->exitCode();

                if ($interruptExit !== null) {
                    ($this->err)("Interrupted. Integration fixture teardown was attempted before exit.\n");
                }

                return $interruptExit ?? self::EXIT_FAILURE;
            }

            // Merge before an early return. Thus, this operation restores relay
            // variables and removes the shared directory for interrupted or empty
            // runs.
            $coverage = $coverageSession->finish($run->coverage);
        } finally {
            $coverageSession->close();
        }

        // Apply the filter after all source maps merge. Thus, exclusion markers
        // apply to worker, orchestrator, and relayed coverage.
        if ($coverage instanceof CoverageMap) {
            $coverage = new IgnoreFilter()->apply($coverage);
        }

        $reporter->finish();
        $this->persistRunState($state, $failedTap->failedTests(), $failedTap->classSeconds());

        $interruptExit = $shutdown->exitCode();

        if ($interruptExit !== null) {
            ($this->err)("Interrupted. The summary includes only tests that finished before shutdown.\n");

            return $interruptExit;
        }

        if ($run->plannedTests === 0) {
            ($this->err)("Greenlight found no tests. Check the configuration, test paths, and filters.\n");

            return self::EXIT_FAILURE;
        }

        $coverageConfig = $resolved->coverage;

        if ($coverageConfig instanceof CoverageConfiguration) {
            if (!$coverage instanceof CoverageMap) {
                ($this->err)("No worker collected the requested coverage. Install pcov or enable Xdebug with coverage mode.\n");
            } elseif (!$this->writeCoverage($coverageConfig, $coverage, $workingDirectory, $this->stdoutStyle($arguments->has('no-ansi')))) {
                return self::EXIT_FAILURE;
            }
        }

        if ($run->leaks !== []) {
            ($this->err)(SummaryFormat::leaks($run->leaks, $this->stderrStyle($arguments->has('no-ansi'))));

            return self::EXIT_FAILURE;
        }

        return $run->summary->isSuccessful() ? self::EXIT_OK : self::EXIT_FAILURE;
    }

    /**
     * Gives a warning when Greenlight cannot save run state.
     *
     * A storage failure does not change the exit code.
     *
     * @param list<non-empty-string> $failedTests
     * @param array<non-empty-string, float> $classSeconds
     */
    private function persistRunState(RunState $state, array $failedTests, array $classSeconds): void
    {
        if (!$state->record($failedTests, $classSeconds)) {
            ($this->err)("Greenlight did not save run state. On the next run, --failed and longest-first scheduling have no prior data.\n");
        }
    }

    /**
     * @param list<non-empty-string> $directories
     * @param positive-int $workers
     * @param non-empty-string|false $workerBin
     * @param list<non-empty-string> $priorityClasses
     * @param array<string, float> $classSeconds
     * @throws AttachmentError
     * @throws DiscoveryError
     * @throws IntegrationFixtureError
     * @throws ProtocolError
     * @throws ReportingError
     * @throws WireError
     */
    private function coordinateRun(
        Configuration $configuration,
        array $directories,
        EventSink $sink,
        int $workers,
        string|false $workerBin,
        string $workingDirectory,
        ?CoverageSettings $coverageSettings,
        string $configFile,
        bool $detectLeaks,
        array $priorityClasses,
        array $classSeconds,
        GracefulShutdown $shutdown,
        Reporter $reporter,
    ): RunResult {
        $execution = $this->executionAdapter(
            $workers,
            $workerBin,
            $workingDirectory,
            $coverageSettings,
            $configFile,
            $detectLeaks,
            $shutdown,
            $reporter,
        );

        return new RunCoordinator($workingDirectory)->run(
            $configuration,
            $directories,
            $sink,
            $execution,
            $priorityClasses,
            $classSeconds,
        );
    }

    /**
     * @param positive-int $workers
     * @param non-empty-string|false $workerBin
     */
    private function executionAdapter(
        int $workers,
        string|false $workerBin,
        string $workingDirectory,
        ?CoverageSettings $coverageSettings,
        string $configFile,
        bool $detectLeaks,
        GracefulShutdown $shutdown,
        Reporter $reporter,
    ): ExecutionAdapter {
        if ($workers === 1 || $workerBin === false) {
            return new InProcessExecution($coverageSettings, $detectLeaks, $shutdown);
        }

        return new ProcessPoolExecution(
            [\PHP_BINARY, $workerBin],
            $workingDirectory,
            $workers,
            $coverageSettings,
            $configFile,
            $detectLeaks,
            $shutdown,
            $reporter instanceof Ticking ? $reporter : null,
        );
    }

    /**
     * Gives a warning when an exclude-path prefix matches no discovered test files.
     * Discovery reports enumeration errors separately.
     */
    private function warnWhenExcludePathsMatchNothing(Configuration $resolved, string $workingDirectory, bool $noAnsiFlag): void
    {
        if ($resolved->excludePaths === []) {
            return;
        }

        try {
            $files = new TestDiscoverer()->testFiles($this->directories($resolved, $workingDirectory));
        } catch (DiscoveryError) {
            return;
        }

        foreach ($resolved->excludePaths as $prefix) {
            if (!\array_any($files, static fn(string $file): bool => \str_starts_with($file, $prefix))) {
                ($this->err)($this->stderrStyle($noAnsiFlag)->warn(\sprintf('Warning: --exclude-path "%s" did not match a discovered test file.', $prefix)) . "\n");
            }
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

    private function printError(string $message, bool $noAnsiFlag): void
    {
        ($this->err)($this->stderrStyle($noAnsiFlag)->error('greenlight:') . ' ' . $message . "\n");
    }

    private function stdoutStyle(bool $noAnsiFlag): Style
    {
        $capabilities = TerminalCapabilities::detect(
            Terminal::isTty($this->stdout),
            ['CI' => \getenv('CI'), 'NO_COLOR' => \getenv('NO_COLOR')],
            $noAnsiFlag,
        );

        return new Style($capabilities->color);
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

    /** @param non-empty-string|false $workerBin */
    private function watchCommand(
        ParsedArguments $arguments,
        string $workingDirectory,
        string|false $workerBin,
        Configuration $resolved,
        string $configFile,
        GracefulShutdown $shutdown,
        ReporterCatalog $reporterCatalog,
        Reporter $initialReporter,
    ): int {
        $directories = $this->directories($resolved, $workingDirectory);
        $watched = $directories;

        foreach ($resolved->coverage->includePaths ?? [] as $path) {
            $absolute = $this->absolutePath($path, $workingDirectory);

            if ($absolute !== '' && !\in_array($absolute, $watched, true)) {
                $watched[] = $absolute;
            }
        }

        $workers = $resolved->workers->fixed ?? CpuCores::count();
        $coverageSettings = CoverageSettingsResolver::resolve($resolved->coverage, $workingDirectory);
        $detectLeaks = $arguments->has('detect-leaks');
        $storage = StorageLayout::resolve($resolved->storage, $workingDirectory);
        $this->warnWhenLeakDetectionIsUnreliable($detectLeaks, $arguments->has('no-ansi'));
        $nextReporter = $initialReporter;

        $runOnce =
            function (array $priorityClasses) use ($arguments, $resolved, $directories, $workers, $workerBin, $workingDirectory, $coverageSettings, $configFile, $detectLeaks, $shutdown, $storage, $reporterCatalog, &$nextReporter): array {
                $priorityClasses = \array_values(\array_filter(
                    $priorityClasses,
                    static fn(mixed $class): bool => \is_string($class) && $class !== '',
                ));

                $reporter = $nextReporter ?? $this->buildReporter($arguments, $reporterCatalog);
                $nextReporter = null;

                $tap = new ClassFailureTap($failedTap = new FailedTestsTap(new ReporterSink($reporter)));

                $state = RunState::forFile($storage->runStateFile);
                $classSeconds = $resolved->randomizeOrder ? [] : $state->classSeconds();

                try {
                    $this->coordinateRun(
                        $resolved,
                        $directories,
                        $tap,
                        $workers,
                        $workerBin,
                        $workingDirectory,
                        $coverageSettings,
                        $configFile,
                        $detectLeaks,
                        $priorityClasses,
                        $classSeconds,
                        $shutdown,
                        $reporter,
                    );
                } catch (AttachmentError|DiscoveryError|IntegrationFixtureError|ProtocolError $error) {
                    $reporter->finish();
                    $this->printError($error->getMessage(), $arguments->has('no-ansi'));

                    return $priorityClasses;
                }

                $reporter->finish();
                $this->persistRunState($state, $failedTap->failedTests(), $failedTap->classSeconds());

                return $tap->failedClasses();
            };

        $keys = new StdinKeyInput();

        try {
            new WatchLoop(
                new StatChangeDetector($watched),
                new Debouncer($resolved->watch->debounceMilliseconds / 1000),
                $keys,
                new SystemWatchClock(),
                $this->out,
                $shutdown,
            )->run($runOnce);
        } catch (ReporterProviderError $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_FAILURE;
        } finally {
            $keys->restore();
        }

        return $shutdown->exitCode() ?? self::EXIT_OK;
    }

    private function writeCoverage(CoverageConfiguration $configuration, CoverageMap $coverage, string $workingDirectory, Style $style): bool
    {
        ($this->out)("\n" . SummaryFormat::coverage(
            $coverage->totalPercentage(),
            $coverage->coveredLineTotal(),
            $coverage->executableLineTotal(),
            $style,
        ) . "\n");

        foreach ($configuration->exports as $export) {
            $exporter = $this->exporterFor($export->format, $workingDirectory);

            if (!$exporter instanceof CoverageExporter) {
                ($this->err)(\sprintf("Unknown coverage export format \"%s\".\n", $export->format));

                return false;
            }

            $files = $exporter->export($coverage);
            $target = $this->absolutePath($export->target, $workingDirectory);

            if (\count($files) === 1) {
                ErrorTrap::run(static fn() => \mkdir(\dirname($target), 0o777, true));

                try {
                    AtomicFile::write($target, \reset($files));
                } catch (AtomicFileError $error) {
                    ($this->err)(\sprintf("Greenlight could not write the coverage export to \"%s\": %s\n", $target, $error->getMessage()));

                    return false;
                }
            } else {
                ErrorTrap::run(static fn() => \mkdir($target, 0o777, true));

                foreach ($files as $name => $content) {
                    try {
                        AtomicFile::write($target . '/' . $name, $content);
                    } catch (AtomicFileError $error) {
                        ($this->err)(\sprintf("Greenlight could not write the coverage export to \"%s\": %s\n", $target . '/' . $name, $error->getMessage()));

                        return false;
                    }
                }
            }

            ($this->out)(SummaryFormat::coverageExport($export->format, $export->target) . "\n");
        }

        return true;
    }

    private function exporterFor(string $format, string $workingDirectory): ?CoverageExporter
    {
        return match ($format) {
            'lcov' => new LcovExporter(),
            'clover' => new CloverExporter(),
            'cobertura' => new CoberturaExporter(),
            'html' => new HtmlExporter($workingDirectory),
            'json' => new JsonExporter(),
            default => null,
        };
    }

    private function coverageDiffCommand(ParsedArguments $arguments, string $workingDirectory): int
    {
        $baselinePath = $arguments->value('baseline');
        $currentPath = $arguments->value('current');

        if ($baselinePath === null || $currentPath === null) {
            ($this->err)("coverage:diff requires --baseline=<path> and --current=<path>.\n");

            return self::EXIT_USAGE;
        }

        $maps = [];

        foreach (['baseline' => $baselinePath, 'current' => $currentPath] as $label => $path) {
            $absolute = $this->absolutePath($path, $workingDirectory);
            $json = ErrorTrap::run(static fn() => \file_get_contents($absolute), $warning);

            if ($json === false) {
                $this->printError(\sprintf('Greenlight could not read the %s coverage export at "%s"%s.', $label, $path, $warning === null ? '' : ': ' . $warning), $arguments->has('no-ansi'));

                return self::EXIT_FAILURE;
            }

            try {
                $maps[$label] = JsonExporter::import($json);
            } catch (\Throwable $error) {
                $this->printError(\sprintf('The %s file is not a valid coverage export: %s', $label, $error->getMessage()), $arguments->has('no-ansi'));

                return self::EXIT_FAILURE;
            }
        }

        $report = BaselineDiff::between($maps['baseline'], $maps['current']);

        ($this->out)(\sprintf(
            "Coverage: baseline %.2f%%, current %.2f%% (%+.2f)\n",
            $report->baselinePercentage,
            $report->currentPercentage,
            $report->totalDelta(),
        ));

        foreach ($report->fileDeltas as $delta) {
            if ($delta->delta() === 0.0 && $delta->newlyUncoveredLines === []) {
                continue;
            }

            $line = \sprintf(
                '%s: %s -> %s (%+.2f)',
                $delta->file,
                $delta->baselinePercentage === null ? 'absent' : \sprintf('%.2f%%', $delta->baselinePercentage),
                $delta->currentPercentage === null ? 'absent' : \sprintf('%.2f%%', $delta->currentPercentage),
                $delta->delta(),
            );

            if ($delta->newlyUncoveredLines !== []) {
                $line .= ', newly uncovered lines: ' . \implode(', ', $delta->newlyUncoveredLines);
            }

            ($this->out)($line . "\n");
        }

        if ($report->hasRegressions()) {
            ($this->err)("Coverage regressed against the baseline.\n");

            return self::EXIT_FAILURE;
        }

        return self::EXIT_OK;
    }

    /**
     * @param list<Plugin> $plugins
     *
     * @throws ReporterProviderError
     */
    private function reporterCatalog(
        ParsedArguments $arguments,
        array $plugins,
        ?int $seed,
        string $configFile,
        string $workingDirectory,
        bool $workerFallback = false,
    ): ReporterCatalog {
        $capabilities = TerminalCapabilities::detect(
            Terminal::isTty($this->stdout),
            ['CI' => \getenv('CI'), 'NO_COLOR' => \getenv('NO_COLOR')],
            $arguments->has('no-ansi'),
            $arguments->has('ansi'),
        );

        $prefix = \rtrim($workingDirectory, '/') . '/';
        $displayedConfig = \str_starts_with($configFile, $prefix) ? \substr($configFile, \strlen($prefix)) : $configFile;
        $header = new RunHeader(self::VERSION, $displayedConfig, $seed, workerFallback: $workerFallback);
        $profile = $arguments->has('profile');
        $definitions = [
            new ReporterDefinition(
                'tty',
                static fn(Output $output): Reporter => new TtyReporter(
                    $output,
                    $capabilities->color,
                    $capabilities->interactive,
                    $header,
                    extendedSlowTests: $profile,
                    verbose: $arguments->has('verbose'),
                    terminalRows: TerminalRowsResolver::resolve(),
                ),
            ),
            new ReporterDefinition(
                'plain',
                static fn(Output $output): Reporter => new PlainReporter($output, $header, extendedSlowTests: $profile),
            ),
            new ReporterDefinition('junit', static fn(Output $output): Reporter => new JUnitReporter($output)),
            new ReporterDefinition('jsonl', static fn(Output $output): Reporter => new JsonLinesReporter($output)),
            new ReporterDefinition('github', static fn(Output $output): Reporter => new GithubReporter($output)),
            new ReporterDefinition('teamcity', static fn(Output $output): Reporter => new TeamCityReporter($output)),
        ];

        foreach ($plugins as $plugin) {
            if (!$plugin instanceof ReporterProvider) {
                continue;
            }

            try {
                $provided = $plugin->reporters();
            } catch (\Throwable $error) {
                throw ReporterProviderError::providerFailed($plugin::class, $error);
            }

            $position = 0;

            foreach ($provided as $definition) {
                ++$position;

                if (!$definition instanceof ReporterDefinition) {
                    throw ReporterProviderError::invalidDefinition($plugin::class, $position);
                }

                $definitions[] = $definition;
            }
        }

        return new ReporterCatalog($definitions);
    }

    /**
     * @throws CliError
     * @throws ReporterProviderError
     */
    private function buildReporter(ParsedArguments $arguments, ReporterCatalog $catalog): Reporter
    {
        $output = new StreamOutput($this->stdout);
        $capabilities = TerminalCapabilities::detect(
            Terminal::isTty($this->stdout),
            ['CI' => \getenv('CI'), 'NO_COLOR' => \getenv('NO_COLOR')],
            $arguments->has('no-ansi'),
            $arguments->has('ansi'),
        );
        $names = $arguments->values('reporter');

        if ($names === []) {
            $names = [$capabilities->interactive || $capabilities->color ? 'tty' : 'plain'];
        }

        $reporters = [];

        foreach ($names as $name) {
            $reporters[] = $catalog->create($name, $output);
        }

        if ($arguments->has('profile')) {
            $reporters[] = new ProfileReporter($output, new Style($capabilities->color));
        }

        return \count($reporters) === 1 ? $reporters[0] : new CompositeReporter($reporters);
    }

    /** Uses the loaded configuration so IDE and PHPStan signatures match. */
    private function ideHelperCommand(ParsedArguments $arguments, string $workingDirectory): int
    {
        try {
            $configFile = $arguments->value('config') ?? \rtrim($workingDirectory, '/') . '/' . ConfigLoader::FILE_NAME;
            $map = MatcherMap::fromConfigFiles([$this->absolutePath($configFile, $workingDirectory)]);
        } catch (ConfigFileError|InvalidConfiguration|MatcherMapError $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_FAILURE;
        }

        if ($map->names() === []) {
            ($this->out)("The configuration has no extension matchers. There is no helper to generate.\n");

            return self::EXIT_OK;
        }

        $output = $arguments->value('output') ?? '_greenlight_ide_helper.php';
        $path = $this->absolutePath($output, $workingDirectory);

        try {
            AtomicFile::write($path, IdeHelper::render($map));
        } catch (AtomicFileError $error) {
            ($this->err)(\sprintf("Greenlight could not write \"%s\": %s\n", $path, $error->getMessage()));

            return self::EXIT_FAILURE;
        }

        ($this->out)(\sprintf(
            "Wrote %s with %d matchers. Add it to .gitignore. Generate it again after matcher changes.\n",
            $path,
            \count($map->names()),
        ));

        return self::EXIT_OK;
    }

    /**
     * The completion flags and parser use the same OptionSpec list.
     *
     * @param list<string> $rest the arguments after the completion command word
     */
    private function completionCommand(array $rest): int
    {
        $shell = $rest[0] ?? null;

        if ($shell === null) {
            ($this->err)(\sprintf("completion requires a shell argument: %s.\n", \implode(', ', CompletionScripts::SHELLS)));

            return self::EXIT_USAGE;
        }

        $script = new CompletionScripts($this->optionSpecs())->render($shell);

        if ($script === null) {
            ($this->err)(\sprintf("Unknown shell \"%s\". Select one of: %s.\n", $shell, \implode(', ', CompletionScripts::SHELLS)));

            return self::EXIT_USAGE;
        }

        ($this->out)($script);

        return self::EXIT_OK;
    }

    /**
     * Creates a run profile from a saved JSONL event stream.
     * @throws WireError
     */
    private function profileReportCommand(ParsedArguments $arguments, string $workingDirectory): int
    {
        $input = $arguments->value('input');

        if ($input === null || $input === '') {
            ($this->err)("profile:report requires --input=<path to a JSONL stream>.\n");

            return self::EXIT_USAGE;
        }

        $path = $this->absolutePath($input, $workingDirectory);
        $raw = ErrorTrap::run(static fn() => \file_get_contents($path), $warning);

        if (!\is_string($raw)) {
            ($this->err)(\sprintf("Greenlight could not read \"%s\"%s.\n", $path, $warning === null ? '' : ': ' . $warning));

            return self::EXIT_FAILURE;
        }

        $aggregator = new ProfileAggregator();

        foreach (\explode("\n", $raw) as $line) {
            if (\trim($line) === '') {
                continue;
            }

            try {
                $decoded = \json_decode($line, true, 32, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                ($this->err)("The input is not a JSONL event stream. A line is not valid JSON.\n");

                return self::EXIT_FAILURE;
            }

            if (!\is_array($decoded) || ($decoded !== [] && \array_is_list($decoded))) {
                ($this->err)("The input is not a JSONL event stream. A line does not contain an event envelope.\n");

                return self::EXIT_FAILURE;
            }

            $envelope = [];

            foreach ($decoded as $key => $value) {
                if (!\is_string($key)) {
                    ($this->err)("The input is not a JSONL event stream. A line does not contain an event envelope.\n");

                    return self::EXIT_FAILURE;
                }

                $envelope[$key] = $value;
            }

            try {
                $version = Wire::int($envelope, 'v');
                $tag = Wire::nonEmptyString($envelope, 'event');
                $data = Wire::map($envelope, 'data');
            } catch (InvalidWirePayload) {
                ($this->err)("The input is not a JSONL event stream. A line does not contain an event envelope.\n");

                return self::EXIT_FAILURE;
            }

            if (!\in_array($version, [2, 3], true)) {
                ($this->err)(\sprintf("The input uses unsupported JSONL version %d.\n", $version));

                return self::EXIT_FAILURE;
            }

            $class = EventTags::classFor($tag);

            if ($class === null) {
                continue;
            }

            try {
                $aggregator->onEvent($class::fromWire($data));
            } catch (InvalidWirePayload $error) {
                $this->printError(\sprintf('Greenlight could not decode a "%s" event: %s', $tag, $error->getMessage()), $arguments->has('no-ansi'));

                return self::EXIT_FAILURE;
            }
        }

        $report = $aggregator->render(new Style(TerminalCapabilities::detect(
            Terminal::isTty($this->stdout),
            ['CI' => \getenv('CI'), 'NO_COLOR' => \getenv('NO_COLOR')],
            $arguments->has('no-ansi'),
            $arguments->has('ansi'),
        )->color));

        if ($report === '') {
            ($this->err)("The stream has no finished run to profile.\n");

            return self::EXIT_FAILURE;
        }

        ($this->out)(\ltrim($report, "\n"));

        return self::EXIT_OK;
    }

    private function listTestsCommand(ParsedArguments $arguments, string $workingDirectory): int
    {
        try {
            [$resolved] = $this->loadConfiguration($arguments, $workingDirectory);
        } catch (CliError $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_USAGE;
        } catch (ConfigFileError|InvalidConfiguration $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_FAILURE;
        }

        $this->warnWhenExcludePathsMatchNothing($resolved, $workingDirectory, $arguments->has('no-ansi'));

        try {
            $plan = $this->discoverSelection($resolved, $workingDirectory);
        } catch (DiscoveryError $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_FAILURE;
        }

        return $this->printTestList($plan);
    }

    /**
     * Discovers the selection that a run executes. It applies all filters from
     * the resolved configuration. If requested, it then applies the shard.
     *
     * @throws DiscoveryError
     */
    private function discoverSelection(Configuration $resolved, string $workingDirectory): ExecutionPlan
    {
        $filter = SelectionFilter::fromConfiguration($resolved);

        $directories = $this->directories($resolved, $workingDirectory);
        $storage = StorageLayout::resolve($resolved->storage, $workingDirectory);
        $plan = new TestDiscoverer()->discover(
            $directories,
            $filter,
            $resolved->randomSeed,
            DiscoveryCache::forDirectories($directories, $storage->cacheDirectory),
        );

        if ($resolved->shard !== null) {
            return PlanShard::select($plan, \max(1, $resolved->shard[0]), \max(1, $resolved->shard[1]));
        }

        return $plan;
    }

    private function printTestList(ExecutionPlan $plan): int
    {
        // Use plan order, not alphabetical order. The list shows the execution
        // order, which includes changes from a seed.
        foreach ($plan->entries as $entry) {
            ($this->out)($entry->id . "\n");
        }

        ($this->out)(\sprintf("\n%d tests\n", \count($plan->entries)));

        return self::EXIT_OK;
    }

    private function printGroupList(ExecutionPlan $plan): int
    {
        $counts = [];

        foreach ($plan->entries as $entry) {
            foreach ($entry->metadata->groups as $group) {
                $counts[$group] = ($counts[$group] ?? 0) + 1;
            }
        }

        \ksort($counts, \SORT_STRING);

        foreach ($counts as $group => $count) {
            ($this->out)(\sprintf("%s (%d tests)\n", $group, $count));
        }

        ($this->out)(\sprintf("\n%d groups\n", \count($counts)));

        return self::EXIT_OK;
    }

    private function printSuiteList(Configuration $resolved): int
    {
        $suites = $resolved->suites;
        \usort($suites, static fn(SuiteConfiguration $a, SuiteConfiguration $b): int => \strcmp($a->name, $b->name));

        foreach ($suites as $suite) {
            $line = $suite->name . ': ' . \implode(', ', $suite->paths);

            if ($suite->tags !== []) {
                $line .= ' [tags: ' . \implode(', ', $suite->tags) . ']';
            }

            ($this->out)($line . "\n");
        }

        ($this->out)(\sprintf("\n%d suites\n", \count($suites)));

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

        $resolved = ConfigurationResolver::resolve($builder->build(), $overrides);

        if ($resolved->excludePaths !== []) {
            $resolved = $resolved->withExcludePaths($this->resolvedPathPrefixes($resolved->excludePaths, $workingDirectory));
        }

        return [$resolved, $configFile];
    }

    /**
     * Discovery reports absolute file paths after it resolves symbolic links.
     * This method resolves prefixes against the current directory. If a prefix
     * exists, it converts the prefix to its canonical form before comparison.
     * A prefix that does not exist keeps its absolute noncanonical form.
     *
     * @param list<non-empty-string> $prefixes
     *
     * @return list<non-empty-string>
     */
    private function resolvedPathPrefixes(array $prefixes, string $workingDirectory): array
    {
        $resolved = [];

        foreach ($prefixes as $prefix) {
            $absolute = $this->absolutePath($prefix, $workingDirectory);
            $real = ErrorTrap::run(static fn() => \realpath($absolute));

            if ($real !== false) {
                $resolved[] = $real;
            } elseif ($absolute !== '') {
                $resolved[] = $absolute;
            }
        }

        return $resolved;
    }

    /**
     * Greenlight starts and controls workers with process and stream functions.
     * This operation does not require an extension. If PHP disables a required
     * function, Greenlight uses a sequential in-process run.
     */
    private function canSpawnWorkers(): bool
    {
        return \array_all(
            self::WORKER_RUNTIME_FUNCTIONS,
            static fn(string $function): bool => \function_exists($function),
        );
    }

    /** @return non-empty-string|false */
    private function workerBinPath(?string $binPath): string|false
    {
        if ($binPath === null || !$this->canSpawnWorkers()) {
            return false;
        }

        return ErrorTrap::run(static fn() => \realpath($binPath));
    }

    /**
     * Returns unique configured top-level and suite paths.
     *
     * The method resolves the paths against the current directory.
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
        return new ArgumentParser($this->optionSpecs());
    }

    /**
     * Defines the options that the parser accepts and the completion scripts offer.
     *
     * @return list<OptionSpec>
     */
    private function optionSpecs(): array
    {
        return [
            new OptionSpec('config', OptionValue::Required),
            new OptionSpec('workers', OptionValue::Required),
            new OptionSpec('resource-limit', OptionValue::Required, repeatable: true),
            new OptionSpec('bail', OptionValue::Optional),
            new OptionSpec('group', OptionValue::Required, repeatable: true),
            new OptionSpec('filter', OptionValue::Required, repeatable: true),
            new OptionSpec('test-id', OptionValue::Required, repeatable: true),
            new OptionSpec('exclude-group', OptionValue::Required, repeatable: true),
            new OptionSpec('exclude-class', OptionValue::Required, repeatable: true),
            new OptionSpec('exclude-method', OptionValue::Required, repeatable: true),
            new OptionSpec('exclude-path', OptionValue::Required, repeatable: true),
            new OptionSpec('list-tests'),
            new OptionSpec('list-groups'),
            new OptionSpec('list-suites'),
            new OptionSpec('repeat', OptionValue::Required),
            new OptionSpec('repeat-until-failure'),
            new OptionSpec('failed'),
            new OptionSpec('shard', OptionValue::Required),
            new OptionSpec('fail-on-deprecation'),
            new OptionSpec('fail-on-notice'),
            new OptionSpec('fail-on-risky'),
            new OptionSpec('seed', OptionValue::Required),
            new OptionSpec('reporter', OptionValue::Required, repeatable: true),
            new OptionSpec('artifacts-dir', OptionValue::Required),
            new OptionSpec('baseline', OptionValue::Required),
            new OptionSpec('current', OptionValue::Required),
            new OptionSpec('watch'),
            new OptionSpec('detect-leaks'),
            new OptionSpec('dry-run'),
            new OptionSpec('ansi'),
            new OptionSpec('no-ansi'),
            new OptionSpec('verbose'),
            new OptionSpec('profile'),
            new OptionSpec('input', OptionValue::Required),
            new OptionSpec('output', OptionValue::Required),
            new OptionSpec('help', short: 'h'),
            new OptionSpec('version', short: 'V'),
        ];
    }

    private function absolutePath(string $path, string $workingDirectory): string
    {
        if (\str_starts_with($path, '/')) {
            return $path;
        }

        return \rtrim($workingDirectory, '/') . '/' . $path;
    }
}
