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
use Greenlight\Config\SuiteConfiguration;
use Greenlight\Core\AtomicFile;
use Greenlight\Core\AtomicFileError;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Event\EventTags;
use Greenlight\Core\GracefulShutdown;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Diff\BaselineDiff;
use Greenlight\Coverage\Export\CloverExporter;
use Greenlight\Coverage\Export\CoberturaExporter;
use Greenlight\Coverage\Export\CoverageExporter;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Coverage\Export\LcovExporter;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\PhpStan\IdeHelper;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\PhpStan\MatcherMapError;
use Greenlight\Reporting\CompositeReporter;
use Greenlight\Reporting\GithubReporter;
use Greenlight\Reporting\JsonLinesReporter;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Reporting\Output\StreamOutput;
use Greenlight\Reporting\PlainReporter;
use Greenlight\Reporting\ProfileAggregator;
use Greenlight\Reporting\ProfileReporter;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\RunHeader;
use Greenlight\Reporting\Style;
use Greenlight\Reporting\SummaryFormat;
use Greenlight\Reporting\TeamCityReporter;
use Greenlight\Reporting\Ticking;
use Greenlight\Reporting\TtyReporter;
use Greenlight\Runner\CoverageCollector;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\CpuCores;
use Greenlight\Runner\InProcessRunner;
use Greenlight\Runner\ParallelRunner;
use Greenlight\Runner\PlanShard;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\SelectionFilter;
use Greenlight\Runner\SharedCoverageDirectory;
use Greenlight\Runner\SubprocessCoverage;
use Greenlight\Runner\Worker\LeakDetector;
use Greenlight\Runner\Worker\WorkerProcess;

/**
 * The greenlight command.
 *
 * run() parses arguments, loads greenlight.php, applies command-line
 * overrides, and dispatches to a command: run executes the tests (or prints
 * the resolved plan under --dry-run), list-tests prints every discovered
 * test id.
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
          run            Discover and execute tests (default)
          list-tests     List every discovered test id, one per line
          coverage:diff  Compare two coverage JSON exports (--baseline, --current)
          profile:report Render the run profile from a saved jsonl stream (--input)
          ide-helper     Write the IDE autocomplete helper for extension matchers
                         (--output, default _greenlight_ide_helper.php)
          completion     Print a shell completion script for bash, zsh, or fish
                         to stdout, e.g. source <(greenlight completion bash)

        Options:
          --config=<path>    Use this config file instead of ./greenlight.php
          --workers=<n|auto> Worker process count
          --bail[=<n>]       Stop after <n> failures (default 1)
          --group=<name>     Only run this group; repeatable
          --filter=<pattern> Only run tests whose id matches; substring, or
                             full match with * wildcards; repeatable
          --exclude-group=<name>     Skip tests in this group; repeatable
          --exclude-class=<pattern>  Skip classes matching this pattern;
                             substring or * wildcards; repeatable
          --exclude-method=<pattern> Skip methods matching this pattern;
                             substring or * wildcards; repeatable
          --exclude-path=<prefix>    Skip test files under this path prefix;
                             repeatable
          --failed           Only re-run tests that failed in the previous run
          --list-tests       Print the selected test ids instead of running
          --list-groups      Print each selected group and its test count
          --list-suites      Print the configured suites
          --repeat=<n>       Run the selected tests n times in fresh runs;
                             any failing iteration fails the command
          --repeat-until-failure  Repeat until an iteration fails, up to
                             --repeat times (default at most 100)
          --shard=<n>/<m>    Run the nth of m disjoint slices of the plan; whole
                             classes, stable across machines, no coordination
          --seed=<n>         Randomize class order with this seed
          --reporter=<name>  Output format: tty, plain, junit, jsonl, github, teamcity; repeatable
          --watch            Re-run on file changes; Enter re-runs everything, q quits
          --detect-leaks     Verify every test instance is collected; leaks fail the run
          --verbose          Print a permanent line per completed class in
                             interactive output
          --no-ansi          Disable colours and the live progress window;
                             plain append-only output
          --fail-on-deprecation  Fail passed tests that captured a deprecation
          --fail-on-notice   Fail passed tests that captured a notice
          --fail-on-risky    Fail passed tests that verified no expectations
          --profile          Append a run profile (worker utilisation, boot latency,
                             makespan spread, slowest classes) after the summary
                             and extend the slowest-tests list
          --dry-run          Print the resolved configuration without executing
          -h, --help         Show this help
          -V, --version      Show the version

        HELP;

    /**
     * @param \Closure(string): void $out
     * @param \Closure(string): void $err
     */
    private function __construct(private \Closure $out, private \Closure $err) {}

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
    public function run(array $argv, string $workingDirectory, ?string $binPath = null): int
    {
        // Internal worker entry, spawned by the orchestrator. Bypasses the
        // normal parser; undocumented and carries no compatibility promise.
        if (($argv[0] ?? null) === '__worker') {
            if (\count($argv) !== 4 || $argv[1] === '' || $argv[2] === '' || $argv[3] === '') {
                ($this->err)("__worker requires <address> <workerId> <token>.\n");

                return self::EXIT_USAGE;
            }

            return new WorkerProcess()->run($argv[1], $argv[2], $argv[3]);
        }

        // A coverage-enabled run exports the relay variables to everything it
        // spawns; a CLI process that inherits them reports its own coverage
        // back through the shared directory.
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
     */
    private function dispatch(array $argv, string $workingDirectory, ?string $binPath): int
    {
        // Handled before parsing because the shell name is a positional
        // argument the parser does not model.
        if (($argv[0] ?? null) === 'completion') {
            return $this->completionCommand(\array_slice($argv, 1));
        }

        try {
            $arguments = $this->parser()->parse($argv);
        } catch (CliError $error) {
            // Parsing failed, so flags are only known from the raw argv.
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

        $this->printError(\sprintf("Unknown command '%s'. Run greenlight --help for the available commands.", $command), $arguments->has('no-ansi'));

        return self::EXIT_USAGE;
    }

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
            $this->printError('--watch cannot be combined with --repeat or --repeat-until-failure.', $arguments->has('no-ansi'));

            return self::EXIT_USAGE;
        }

        if ($arguments->has('dry-run')) {
            ($this->out)(PlanFormatter::format($resolved, $configFile));

            return self::EXIT_OK;
        }

        if ($arguments->has('list-suites')) {
            return $this->printSuiteList($resolved);
        }

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
        $realBin = $binPath === null || !$this->canSpawnWorkers() ? false : \realpath($binPath);

        try {
            $reporter = $this->buildReporter($arguments, $resolved->randomSeed, $configFile, $workingDirectory, workerFallback: $workers > 1 && $realBin === false);
        } catch (CliError $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_USAGE;
        }

        $shutdown = new GracefulShutdown();
        SignalHandlers::install($shutdown);

        if ($arguments->has('watch')) {
            return $this->watchCommand($arguments, $workingDirectory, $binPath, $resolved, $configFile, $shutdown);
        }

        $state = RunState::forWorkingDirectory($workingDirectory);
        $previousFailures = $state->failedTests();

        if ($arguments->has('failed')) {
            if ($previousFailures === null) {
                $this->printError('--failed needs a previous run to have recorded state for this project; run once without it first.', $arguments->has('no-ansi'));

                return self::EXIT_USAGE;
            }

            if ($previousFailures === []) {
                ($this->out)("Nothing failed in the previous run; nothing to re-run.\n");

                return self::EXIT_OK;
            }

            $resolved = $resolved->withOnlyTests($previousFailures);
        }

        $priorityClasses = [];

        if (!$resolved->randomizeOrder && \is_array($previousFailures)) {
            foreach ($previousFailures as $id) {
                $class = \strstr($id, '::', true);

                if (\is_string($class) && $class !== '' && !\in_array($class, $priorityClasses, true)) {
                    $priorityClasses[] = $class;
                }
            }
        }

        $classSeconds = $resolved->randomizeOrder ? [] : $state->classSeconds();
        $this->warnWhenLeakDetectionIsUnreliable($arguments->has('detect-leaks'), $arguments->has('no-ansi'));

        // --repeat=1 is the ordinary single run; the loop, its banners, and
        // its summary only appear when more than one iteration is possible.
        if (($overrides->repeat === null || $overrides->repeat === 1) && !$overrides->repeatUntilFailure) {
            $failedTap = new FailedTestsTap(new ReporterSink($reporter));

            return $this->executeRun($arguments, $resolved, $configFile, $workingDirectory, $binPath, $shutdown, $priorityClasses, $classSeconds, $reporter, $failedTap, $state);
        }

        // --repeat-until-failure without an explicit --repeat cannot loop
        // forever; 100 iterations is the safety limit.
        $limit = $overrides->repeat ?? 100;
        $bounded = $overrides->repeat !== null;
        $failedIterations = [];
        $failedTests = [];
        $lastClassSeconds = [];

        for ($iteration = 1; $iteration <= $limit; $iteration++) {
            ($this->out)($bounded
                ? \sprintf("Repeat: iteration %d of %d\n", $iteration, $limit)
                : \sprintf("Repeat: iteration %d of at most %d\n", $iteration, $limit));

            if ($iteration > 1) {
                try {
                    $reporter = $this->buildReporter($arguments, $resolved->randomSeed, $configFile, $workingDirectory, workerFallback: $workers > 1 && $realBin === false);
                } catch (CliError $error) {
                    $this->printError($error->getMessage(), $arguments->has('no-ansi'));

                    return self::EXIT_USAGE;
                }
            }

            $failedTap = new FailedTestsTap(new ReporterSink($reporter));
            $exit = $this->executeRun($arguments, $resolved, $configFile, $workingDirectory, $binPath, $shutdown, $priorityClasses, $classSeconds, $reporter, $failedTap, $state);

            foreach ($failedTap->failedTests() as $id) {
                if (!\in_array($id, $failedTests, true)) {
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

        // Every test that failed in any iteration is recorded, so a follow-up
        // --failed run replays the flakes even when a later iteration passed.
        $this->persistRunState($state, $failedTests, $lastClassSeconds);

        ($this->out)(\sprintf("Repeat: failed on iteration(s) %s\n", \implode(', ', $failedIterations)));

        return self::EXIT_FAILURE;
    }

    /**
     * One full execution: run the plan, finish the reporter, record run
     * state, and translate the outcome into an exit code.
     *
     * The reporter must be fresh; finish() is called on it exactly once.
     *
     * @param list<non-empty-string> $priorityClasses
     * @param array<string, float> $classSeconds
     */
    private function executeRun(
        ParsedArguments $arguments,
        Configuration $resolved,
        string $configFile,
        string $workingDirectory,
        ?string $binPath,
        GracefulShutdown $shutdown,
        array $priorityClasses,
        array $classSeconds,
        Reporter $reporter,
        FailedTestsTap $failedTap,
        RunState $state,
    ): int {
        $workers = $resolved->workers->fixed ?? CpuCores::count();
        $realBin = $binPath === null || !$this->canSpawnWorkers() ? false : \realpath($binPath);
        $coverageSettings = $this->coverageSettings($resolved->coverage, $workingDirectory);
        $detectLeaks = $arguments->has('detect-leaks');

        $orchestratorCollector = null;
        $shared = null;

        if ($coverageSettings instanceof CoverageSettings) {
            // The orchestrator process collects its own coverage alongside
            // the workers, so runner and reporting code exercised only on
            // this side of the pool shows up in the export. Skipped when this
            // process inherited the relay variables: it is already dumping
            // whole-process coverage, and a second driver window would close
            // that one early. The in-process runner opens its own window, so
            // only the pool path collects here.
            if ($workers !== 1 && $realBin !== false && !SubprocessCoverage::requested()) {
                $orchestratorCollector = CoverageCollector::create($coverageSettings);
                $orchestratorCollector?->start();
            }

            $shared = SharedCoverageDirectory::open($coverageSettings);
        }

        try {
            if ($workers === 1 || $realBin === false) {
                $run = new InProcessRunner()
                    ->run($resolved, $this->directories($resolved, $workingDirectory), $failedTap, $coverageSettings, $detectLeaks, $priorityClasses, $classSeconds, $shutdown);
            } else {
                $run = new ParallelRunner([\PHP_BINARY, $realBin], $workingDirectory)
                    ->run($resolved, $this->directories($resolved, $workingDirectory), $failedTap, $workers, $coverageSettings, $configFile, $detectLeaks, $priorityClasses, $classSeconds, $shutdown, $reporter instanceof Ticking ? $reporter : null);
            }
        } catch (DiscoveryError|ProtocolError $error) {
            $orchestratorCollector?->stop();
            $shared?->drain();
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_FAILURE;
        }

        // Merged before any early return so the relay variables are restored
        // and the shared directory is removed even for interrupted or empty
        // runs.
        $coverage = $run->coverage;

        if ($orchestratorCollector instanceof CoverageCollector) {
            $collected = $orchestratorCollector->stop();

            if (!$collected->isEmpty()) {
                $coverage = $coverage instanceof CoverageMap ? $coverage->merge($collected) : $collected;
            }
        }

        if ($shared instanceof SharedCoverageDirectory) {
            $dumped = $shared->drain();

            if ($dumped instanceof CoverageMap) {
                $coverage = $coverage instanceof CoverageMap ? $coverage->merge($dumped) : $dumped;
            }
        }

        $reporter->finish();
        $this->persistRunState($state, $failedTap->failedTests(), $failedTap->classSeconds());

        $interruptExit = $shutdown->exitCode();

        if ($interruptExit !== null) {
            ($this->err)("Interrupted. The summary covers only the tests that completed before shutdown.\n");

            return $interruptExit;
        }

        if ($run->plannedTests === 0) {
            ($this->err)("No tests found. A misconfigured run must not pass.\n");

            return self::EXIT_FAILURE;
        }

        $coverageConfig = $resolved->coverage;

        if ($coverageConfig instanceof CoverageConfiguration) {
            if (!$coverage instanceof CoverageMap) {
                ($this->err)("Coverage was requested but no worker could collect it. Is pcov or xdebug (mode=coverage) available?\n");
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
     * Records the run's failure set and class durations, warning on stderr
     * when persistence fails: a lost state file silently degrades --failed,
     * failed-first ordering, and longest-first scheduling on the next run,
     * so the loss must be visible without affecting the exit code.
     *
     * @param list<non-empty-string> $failedTests
     * @param array<non-empty-string, float> $classSeconds
     */
    private function persistRunState(RunState $state, array $failedTests, array $classSeconds): void
    {
        if (!$state->record($failedTests, $classSeconds)) {
            ($this->err)("Run state was not saved; --failed and longest-first scheduling start cold next run.\n");
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

    /**
     * Reports a top-level error on stderr, under a red "greenlight:" prefix
     * when stderr supports colour, so errors stand out from other output.
     */
    private function printError(string $message, bool $noAnsiFlag): void
    {
        ($this->err)($this->stderrStyle($noAnsiFlag)->error('greenlight:') . ' ' . $message . "\n");
    }

    /**
     * Colour for stdout messages written outside the reporters, from stdout's
     * own terminal capabilities.
     */
    private function stdoutStyle(bool $noAnsiFlag): Style
    {
        $capabilities = TerminalCapabilities::detect(
            Terminal::isTty(\STDOUT),
            ['CI' => \getenv('CI'), 'NO_COLOR' => \getenv('NO_COLOR')],
            $noAnsiFlag,
        );

        return new Style($capabilities->colour);
    }

    /**
     * Colour for stderr messages, from stderr's own terminal capabilities.
     */
    private function stderrStyle(bool $noAnsiFlag): Style
    {
        $capabilities = TerminalCapabilities::detect(
            Terminal::isTty(\STDERR),
            ['CI' => \getenv('CI'), 'NO_COLOR' => \getenv('NO_COLOR')],
            $noAnsiFlag,
        );

        return new Style($capabilities->colour);
    }

    private function watchCommand(
        ParsedArguments $arguments,
        string $workingDirectory,
        ?string $binPath,
        Configuration $resolved,
        string $configFile,
        GracefulShutdown $shutdown,
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
        $realBin = $binPath === null || !$this->canSpawnWorkers() ? false : \realpath($binPath);
        $coverageSettings = $this->coverageSettings($resolved->coverage, $workingDirectory);
        $detectLeaks = $arguments->has('detect-leaks');
        $this->warnWhenLeakDetectionIsUnreliable($detectLeaks, $arguments->has('no-ansi'));

        $runOnce =
            function (array $priorityClasses) use ($arguments, $resolved, $directories, $workers, $realBin, $workingDirectory, $coverageSettings, $configFile, $detectLeaks, $shutdown): array {
                $priorityClasses = \array_values(\array_filter(
                    $priorityClasses,
                    static fn(mixed $class): bool => \is_string($class) && $class !== '',
                ));

                try {
                    $reporter = $this->buildReporter($arguments, $resolved->randomSeed, $configFile, $workingDirectory, workerFallback: $workers > 1 && $realBin === false);
                } catch (CliError $error) {
                    $this->printError($error->getMessage(), $arguments->has('no-ansi'));

                    return $priorityClasses;
                }

                $tap = new ClassFailureTap($failedTap = new FailedTestsTap(new ReporterSink($reporter)));

                $classSeconds = $resolved->randomizeOrder ? [] : RunState::forWorkingDirectory($workingDirectory)->classSeconds();

                try {
                    if ($workers === 1 || $realBin === false) {
                        new InProcessRunner()
                            ->run($resolved, $directories, $tap, $coverageSettings, $detectLeaks, $priorityClasses, $classSeconds, $shutdown);
                    } else {
                        new ParallelRunner([\PHP_BINARY, $realBin], $workingDirectory)
                            ->run($resolved, $directories, $tap, $workers, $coverageSettings, $configFile, $detectLeaks, $priorityClasses, $classSeconds, $shutdown, $reporter instanceof Ticking ? $reporter : null);
                    }
                } catch (DiscoveryError|ProtocolError $error) {
                    $this->printError($error->getMessage(), $arguments->has('no-ansi'));

                    return $priorityClasses;
                }

                $reporter->finish();
                $this->persistRunState(RunState::forWorkingDirectory($workingDirectory), $failedTap->failedTests(), $failedTap->classSeconds());

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
        } finally {
            $keys->restore();
        }

        return $shutdown->exitCode() ?? self::EXIT_OK;
    }

    private function coverageSettings(?CoverageConfiguration $configuration, string $workingDirectory): ?CoverageSettings
    {
        if (!$configuration instanceof CoverageConfiguration) {
            return null;
        }

        $include = [];

        foreach ($configuration->includePaths as $path) {
            $absolute = $this->absolutePath($path, $workingDirectory);
            $real = \realpath($absolute);

            if ($real !== false) {
                $include[] = $real;
            } elseif ($absolute !== '') {
                $include[] = $absolute;
            }
        }

        return new CoverageSettings($include, $configuration->driver);
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
                ErrorTrap::run(static fn(): bool => \mkdir(\dirname($target), 0o777, true));

                try {
                    AtomicFile::write($target, \reset($files));
                } catch (AtomicFileError $error) {
                    ($this->err)(\sprintf("Could not write coverage export to \"%s\": %s\n", $target, $error->getMessage()));

                    return false;
                }
            } else {
                ErrorTrap::run(static fn(): bool => \mkdir($target, 0o777, true));

                foreach ($files as $name => $content) {
                    try {
                        AtomicFile::write($target . '/' . $name, $content);
                    } catch (AtomicFileError $error) {
                        ($this->err)(\sprintf("Could not write coverage export to \"%s\": %s\n", $target . '/' . $name, $error->getMessage()));

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
            $json = ErrorTrap::run(static fn(): string|false => \file_get_contents($absolute), $warning);

            if ($json === false) {
                $this->printError(\sprintf('Could not read the %s coverage export at "%s"%s.', $label, $path, $warning === null ? '' : ': ' . $warning), $arguments->has('no-ansi'));

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
     * @throws CliError
     */
    private function buildReporter(ParsedArguments $arguments, ?int $seed, string $configFile, string $workingDirectory, bool $workerFallback = false): Reporter
    {
        $output = new StreamOutput(\STDOUT);
        $capabilities = TerminalCapabilities::detect(
            Terminal::isTty(\STDOUT),
            ['CI' => \getenv('CI'), 'NO_COLOR' => \getenv('NO_COLOR')],
            $arguments->has('no-ansi'),
        );

        $names = $arguments->values('reporter');

        if ($names === []) {
            $names = [$capabilities->interactive ? 'tty' : 'plain'];
        }

        $prefix = \rtrim($workingDirectory, '/') . '/';
        $displayedConfig = \str_starts_with($configFile, $prefix) ? \substr($configFile, \strlen($prefix)) : $configFile;
        $header = new RunHeader(self::VERSION, $displayedConfig, $seed, workerFallback: $workerFallback);
        $profile = $arguments->has('profile');
        $reporters = [];

        foreach ($names as $name) {
            $reporters[] = match ($name) {
                'tty' => new TtyReporter(
                    $output,
                    $capabilities->colour,
                    $capabilities->interactive,
                    $header,
                    extendedSlowTests: $profile,
                    verbose: $arguments->has('verbose'),
                    terminalRows: $this->terminalRows(),
                ),
                'plain' => new PlainReporter($output, $header, extendedSlowTests: $profile),
                'junit' => new JUnitReporter($output),
                'jsonl' => new JsonLinesReporter($output),
                'github' => new GithubReporter($output),
                'teamcity' => new TeamCityReporter($output),
                default => throw CliError::unknownReporter($name),
            };
        }

        if ($profile) {
            $reporters[] = new ProfileReporter($output, new Style($capabilities->colour));
        }

        return \count($reporters) === 1 ? $reporters[0] : new CompositeReporter($reporters);
    }

    /**
     * LINES when the shell exports it, tput as fallback, 24 as the safe
     * default; probed once per reporter build, no resize handling.
     */
    private function terminalRows(): int
    {
        $linesEnv = \getenv('LINES');
        $lines = $linesEnv === false || $linesEnv === '' ? 0 : (int) $linesEnv;

        if ($lines > 0) {
            return $lines;
        }

        $probed = (int) ErrorTrap::run(static fn(): string|false => \exec('tput lines 2>/dev/null'));

        return $probed > 0 ? $probed : 24;
    }

    /**
     * Writes the duplicate-declaration helper file IDEs index for extension
     * matcher autocomplete.
     *
     * Loads the same config file the run would, so the generated signatures
     * match what the PHPStan extension enforces.
     */
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
            ($this->out)("No extension matchers are configured; nothing to generate.\n");

            return self::EXIT_OK;
        }

        $output = $arguments->value('output') ?? '_greenlight_ide_helper.php';
        $path = $this->absolutePath($output, $workingDirectory);

        try {
            AtomicFile::write($path, IdeHelper::render($map));
        } catch (AtomicFileError $error) {
            ($this->err)(\sprintf("Could not write \"%s\": %s\n", $path, $error->getMessage()));

            return self::EXIT_FAILURE;
        }

        ($this->out)(\sprintf(
            "Wrote %s with %d matchers. Gitignore it and regenerate after changing matchers.\n",
            $path,
            \count($map->names()),
        ));

        return self::EXIT_OK;
    }

    /**
     * Prints the completion script for the requested shell to stdout. The
     * script is generated from the same option specs the parser is built
     * from, so there is no second flag list to maintain.
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
            ($this->err)(\sprintf("Unknown shell \"%s\". Available: %s.\n", $shell, \implode(', ', CompletionScripts::SHELLS)));

            return self::EXIT_USAGE;
        }

        ($this->out)($script);

        return self::EXIT_OK;
    }

    /**
     * Replays a saved jsonl event stream through the profile aggregator, so
     * a CI run's profile is recoverable from its artifact without a re-run.
     */
    private function profileReportCommand(ParsedArguments $arguments, string $workingDirectory): int
    {
        $input = $arguments->value('input');

        if ($input === null || $input === '') {
            ($this->err)("profile:report requires --input=<path to a jsonl stream>.\n");

            return self::EXIT_USAGE;
        }

        $path = $this->absolutePath($input, $workingDirectory);
        $raw = ErrorTrap::run(static fn(): string|false => \file_get_contents($path), $warning);

        if (!\is_string($raw)) {
            ($this->err)(\sprintf("Could not read \"%s\"%s.\n", $path, $warning === null ? '' : ': ' . $warning));

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
                ($this->err)("The input is not a jsonl event stream: a line is not valid JSON.\n");

                return self::EXIT_FAILURE;
            }

            if (!\is_array($decoded) || !\is_string($decoded['event'] ?? null) || !\is_array($decoded['data'] ?? null)) {
                ($this->err)("The input is not a jsonl event stream: a line is missing the event envelope.\n");

                return self::EXIT_FAILURE;
            }

            $class = EventTags::classFor($decoded['event']);

            if ($class === null) {
                continue;
            }

            $data = [];

            foreach ($decoded['data'] as $key => $value) {
                if (\is_string($key)) {
                    $data[$key] = $value;
                }
            }

            try {
                $aggregator->onEvent($class::fromWire($data));
            } catch (InvalidWirePayload $error) {
                $this->printError(\sprintf('Could not decode a "%s" event: %s', $decoded['event'], $error->getMessage()), $arguments->has('no-ansi'));

                return self::EXIT_FAILURE;
            }
        }

        $report = $aggregator->render(new Style(TerminalCapabilities::detect(
            Terminal::isTty(\STDOUT),
            ['CI' => \getenv('CI'), 'NO_COLOR' => \getenv('NO_COLOR')],
            $arguments->has('no-ansi'),
        )->colour));

        if ($report === '') {
            ($this->err)("The stream contains no finished run to profile.\n");

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

        try {
            $plan = $this->discoverSelection($resolved, $workingDirectory);
        } catch (DiscoveryError $error) {
            $this->printError($error->getMessage(), $arguments->has('no-ansi'));

            return self::EXIT_FAILURE;
        }

        return $this->printTestList($plan);
    }

    /**
     * Discovers the same selection a run would execute: the full filter set
     * from the resolved configuration, then the shard slice when one was
     * requested.
     *
     * @throws DiscoveryError
     */
    private function discoverSelection(Configuration $resolved, string $workingDirectory): ExecutionPlan
    {
        $filter = SelectionFilter::fromConfiguration($resolved);

        $directories = $this->directories($resolved, $workingDirectory);
        $plan = new TestDiscoverer()->discover($directories, $filter, $resolved->randomSeed, DiscoveryCache::forDirectories($directories));

        if ($resolved->shard !== null) {
            $plan = PlanShard::select($plan, \max(1, $resolved->shard[0]), \max(1, $resolved->shard[1]));
        }

        return $plan;
    }

    private function printTestList(ExecutionPlan $plan): int
    {
        // Plan order, not alphabetical: the listing previews the order a run
        // would execute, including seeded shuffles.
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

        return [ConfigurationResolver::resolve($builder->build(), $overrides), $configFile];
    }

    /**
     * Worker processes are spawned with proc_open over core stream sockets,
     * so no extension is required; hosts that put proc_open in
     * disable_functions get an in-process sequential run instead.
     */
    private function canSpawnWorkers(): bool
    {
        return \function_exists('proc_open') && \function_exists('stream_socket_server');
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
        return new ArgumentParser($this->optionSpecs());
    }

    /**
     * The single option table: the parser accepts exactly these options and
     * the completion scripts offer exactly these flags.
     *
     * @return list<OptionSpec>
     */
    private function optionSpecs(): array
    {
        return [
            new OptionSpec('config', OptionValue::Required),
            new OptionSpec('workers', OptionValue::Required),
            new OptionSpec('bail', OptionValue::Optional),
            new OptionSpec('group', OptionValue::Required, repeatable: true),
            new OptionSpec('filter', OptionValue::Required, repeatable: true),
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
            new OptionSpec('baseline', OptionValue::Required),
            new OptionSpec('current', OptionValue::Required),
            new OptionSpec('watch'),
            new OptionSpec('detect-leaks'),
            new OptionSpec('dry-run'),
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
