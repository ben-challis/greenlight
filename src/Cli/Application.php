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
use Greenlight\Core\Event\EventTags;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Diff\BaselineDiff;
use Greenlight\Coverage\Export\CloverExporter;
use Greenlight\Coverage\Export\CoberturaExporter;
use Greenlight\Coverage\Export\CoverageExporter;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Coverage\Export\LcovExporter;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Filter;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Reporting\CompositeReporter;
use Greenlight\Reporting\GithubReporter;
use Greenlight\Reporting\JsonLinesReporter;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Reporting\Output\StreamOutput;
use Greenlight\Reporting\PlainReporter;
use Greenlight\Reporting\ProfileAggregator;
use Greenlight\Reporting\ProfileReporter;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\TeamCityReporter;
use Greenlight\Reporting\TtyReporter;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\CpuCores;
use Greenlight\Runner\InProcessRunner;
use Greenlight\Runner\ParallelRunner;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Worker\WorkerProcess;

/**
 * The greenlight command. Parses arguments, loads greenlight.php, applies
 * command-line overrides, and dispatches to a command: run prints the
 * resolved plan, list-tests prints every discovered test id.
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

        Options:
          --config=<path>    Use this config file instead of ./greenlight.php
          --workers=<n|auto> Worker process count
          --bail[=<n>]       Stop after <n> failures (default 1)
          --group=<name>     Only run this group; repeatable
          --filter=<pattern> Only run tests whose id matches; substring, or
                             full match with * wildcards; repeatable
          --failed           Only re-run tests that failed in the previous run
          --seed=<n>         Randomize class order with this seed
          --reporter=<name>  Output format: tty, plain, junit, jsonl, github, teamcity; repeatable
          --watch            Re-run on file changes; Enter re-runs everything, q quits
          --detect-leaks     Verify every test instance is collected; leaks fail the run
          --profile          Append a run profile (worker utilisation, boot latency,
                             makespan spread, slowest classes) after the summary
          --dry-run          Print the resolved configuration without executing
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

        ($this->err)(\sprintf("Unknown command '%s'. Run greenlight --help for the available commands.\n", $command));

        return self::EXIT_USAGE;
    }

    private function runCommand(ParsedArguments $arguments, string $workingDirectory, ?string $binPath = null): int
    {
        try {
            [$resolved, $configFile] = $this->loadConfiguration($arguments, $workingDirectory);
        } catch (CliError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_USAGE;
        } catch (ConfigFileError|InvalidConfiguration $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }

        if ($arguments->has('dry-run')) {
            ($this->out)(PlanFormatter::format($resolved, $configFile));

            return self::EXIT_OK;
        }

        try {
            $reporter = $this->buildReporter($arguments, $resolved->randomSeed);
        } catch (CliError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_USAGE;
        }

        if ($arguments->has('watch')) {
            return $this->watchCommand($arguments, $workingDirectory, $binPath, $resolved, $configFile);
        }

        $state = RunState::forWorkingDirectory($workingDirectory);
        $previousFailures = $state->failedTests();

        if ($arguments->has('failed')) {
            if ($previousFailures === null) {
                ($this->err)("--failed needs a previous run to have recorded state for this project; run once without it first.\n");

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
        $failedTap = new FailedTestsTap(new ReporterSink($reporter));
        $workers = $resolved->workers->fixed ?? CpuCores::count();
        $realBin = $binPath === null || !$this->canSpawnWorkers() ? false : \realpath($binPath);
        $coverageSettings = $this->coverageSettings($resolved->coverage, $workingDirectory);
        $detectLeaks = $arguments->has('detect-leaks');

        try {
            if ($workers === 1 || $realBin === false) {
                $run = new InProcessRunner()
                    ->run($resolved, $this->directories($resolved, $workingDirectory), $failedTap, $coverageSettings, $detectLeaks, $priorityClasses, $classSeconds);
            } else {
                $run = new ParallelRunner([\PHP_BINARY, $realBin], $workingDirectory)
                    ->run($resolved, $this->directories($resolved, $workingDirectory), $failedTap, $workers, $coverageSettings, $configFile, $detectLeaks, $priorityClasses, $classSeconds);
            }
        } catch (DiscoveryError|ProtocolError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }

        $reporter->finish();
        $state->record($failedTap->failedTests(), $failedTap->classSeconds());

        if ($run->plannedTests === 0) {
            ($this->err)("No tests found. A misconfigured run must not pass.\n");

            return self::EXIT_FAILURE;
        }

        $coverageConfig = $resolved->coverage;

        if ($coverageConfig instanceof CoverageConfiguration) {
            if (!$run->coverage instanceof CoverageMap) {
                ($this->err)("Coverage was requested but no worker could collect it. Is pcov or xdebug (mode=coverage) available?\n");
            } elseif (!$this->writeCoverage($coverageConfig, $run->coverage, $workingDirectory)) {
                return self::EXIT_FAILURE;
            }
        }

        if ($run->leaks !== []) {
            foreach ($run->leaks as $leak) {
                ($this->err)(\sprintf("LEAK %s: the test instance survived its test.\n", $leak));
            }

            return self::EXIT_FAILURE;
        }

        return $run->summary->isSuccessful() ? self::EXIT_OK : self::EXIT_FAILURE;
    }

    private function watchCommand(
        ParsedArguments $arguments,
        string $workingDirectory,
        ?string $binPath,
        Configuration $resolved,
        string $configFile,
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

        $runOnce =
            function (array $priorityClasses) use ($arguments, $resolved, $directories, $workers, $realBin, $workingDirectory, $coverageSettings, $configFile, $detectLeaks): array {
                $priorityClasses = \array_values(\array_filter(
                    $priorityClasses,
                    static fn(mixed $class): bool => \is_string($class) && $class !== '',
                ));

                try {
                    $reporter = $this->buildReporter($arguments, $resolved->randomSeed);
                } catch (CliError $error) {
                    ($this->err)($error->getMessage() . "\n");

                    return $priorityClasses;
                }

                $tap = new ClassFailureTap($failedTap = new FailedTestsTap(new ReporterSink($reporter)));

                $classSeconds = $resolved->randomizeOrder ? [] : RunState::forWorkingDirectory($workingDirectory)->classSeconds();

                try {
                    if ($workers === 1 || $realBin === false) {
                        new InProcessRunner()
                            ->run($resolved, $directories, $tap, $coverageSettings, $detectLeaks, $priorityClasses, $classSeconds);
                    } else {
                        new ParallelRunner([\PHP_BINARY, $realBin], $workingDirectory)
                            ->run($resolved, $directories, $tap, $workers, $coverageSettings, $configFile, $detectLeaks, $priorityClasses, $classSeconds);
                    }
                } catch (DiscoveryError|ProtocolError $error) {
                    ($this->err)($error->getMessage() . "\n");

                    return $priorityClasses;
                }

                $reporter->finish();
                RunState::forWorkingDirectory($workingDirectory)->record($failedTap->failedTests(), $failedTap->classSeconds());

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
            )->run($runOnce);
        } finally {
            $keys->restore();
        }

        return self::EXIT_OK;
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

    private function writeCoverage(CoverageConfiguration $configuration, CoverageMap $coverage, string $workingDirectory): bool
    {
        ($this->out)(\sprintf(
            "Coverage: %.2f%% of %d executable lines\n",
            $coverage->totalPercentage(),
            $coverage->executableLineTotal(),
        ));

        foreach ($configuration->exports as $export) {
            $exporter = $this->exporterFor($export->format);

            if (!$exporter instanceof CoverageExporter) {
                ($this->err)(\sprintf("Unknown coverage export format \"%s\".\n", $export->format));

                return false;
            }

            $files = $exporter->export($coverage);
            $target = $this->absolutePath($export->target, $workingDirectory);

            if (\count($files) === 1) {
                @\mkdir(\dirname($target), 0o777, true);

                if (@\file_put_contents($target, \reset($files)) === false) {
                    ($this->err)(\sprintf("Could not write coverage export to \"%s\".\n", $target));

                    return false;
                }
            } else {
                @\mkdir($target, 0o777, true);

                foreach ($files as $name => $content) {
                    if (@\file_put_contents($target . '/' . $name, $content) === false) {
                        ($this->err)(\sprintf("Could not write coverage export to \"%s\".\n", $target . '/' . $name));

                        return false;
                    }
                }
            }

            ($this->out)(\sprintf("  wrote %s to %s\n", $export->format, $export->target));
        }

        return true;
    }

    private function exporterFor(string $format): ?CoverageExporter
    {
        return match ($format) {
            'lcov' => new LcovExporter(),
            'clover' => new CloverExporter(),
            'cobertura' => new CoberturaExporter(),
            'html' => new HtmlExporter(),
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
            $json = @\file_get_contents($absolute);

            if ($json === false) {
                ($this->err)(\sprintf("Could not read the %s coverage export at \"%s\".\n", $label, $path));

                return self::EXIT_FAILURE;
            }

            try {
                $maps[$label] = JsonExporter::import($json);
            } catch (\Throwable $error) {
                ($this->err)(\sprintf("The %s file is not a valid coverage export: %s\n", $label, $error->getMessage()));

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
    private function buildReporter(ParsedArguments $arguments, ?int $seed): Reporter
    {
        $output = new StreamOutput(\STDOUT);
        $ansi = \function_exists('stream_isatty') && @\stream_isatty(\STDOUT);

        $names = $arguments->values('reporter');

        if ($names === []) {
            $names = [$ansi ? 'tty' : 'plain'];
        }

        $reporters = [];

        foreach ($names as $name) {
            $reporters[] = match ($name) {
                'tty' => new TtyReporter($output, $ansi, $seed),
                'plain' => new PlainReporter($output),
                'junit' => new JUnitReporter($output),
                'jsonl' => new JsonLinesReporter($output),
                'github' => new GithubReporter($output),
                'teamcity' => new TeamCityReporter($output),
                default => throw new CliError(\sprintf(
                    'Unknown reporter "%s". Available: tty, plain, junit, jsonl, github, teamcity.',
                    $name,
                )),
            };
        }

        if ($arguments->has('profile')) {
            $reporters[] = new ProfileReporter($output);
        }

        return \count($reporters) === 1 ? $reporters[0] : new CompositeReporter($reporters);
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
        $raw = @\file_get_contents($path);

        if (!\is_string($raw)) {
            ($this->err)(\sprintf("Could not read \"%s\".\n", $path));

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
                ($this->err)(\sprintf("Could not decode a \"%s\" event: %s\n", $decoded['event'], $error->getMessage()));

                return self::EXIT_FAILURE;
            }
        }

        $report = $aggregator->render();

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
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_USAGE;
        } catch (ConfigFileError|InvalidConfiguration $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }

        $filter = new Filter(includeGroups: $resolved->groups, includeIds: $resolved->filters, includeExactIds: $resolved->onlyTests ?? []);

        try {
            $plan = new TestDiscoverer()->discover($this->directories($resolved, $workingDirectory), $filter, $resolved->randomSeed);
        } catch (DiscoveryError $error) {
            ($this->err)($error->getMessage() . "\n");

            return self::EXIT_FAILURE;
        }

        foreach ($plan->entries as $entry) {
            ($this->out)($entry->id . "\n");
        }

        ($this->out)(\sprintf("\n%d tests\n", \count($plan)));

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
        return new ArgumentParser([
            new OptionSpec('config', OptionValue::Required),
            new OptionSpec('workers', OptionValue::Required),
            new OptionSpec('bail', OptionValue::Optional),
            new OptionSpec('group', OptionValue::Required, repeatable: true),
            new OptionSpec('filter', OptionValue::Required, repeatable: true),
            new OptionSpec('failed'),
            new OptionSpec('seed', OptionValue::Required),
            new OptionSpec('reporter', OptionValue::Required, repeatable: true),
            new OptionSpec('baseline', OptionValue::Required),
            new OptionSpec('current', OptionValue::Required),
            new OptionSpec('watch'),
            new OptionSpec('detect-leaks'),
            new OptionSpec('dry-run'),
            new OptionSpec('profile'),
            new OptionSpec('input', OptionValue::Required),
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
