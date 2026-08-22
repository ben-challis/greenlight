<?php

declare(strict_types=1);

/**
 * Generates synthetic suites and reports wall-time distributions for each
 * benchmark shape. The schedule uses explicit warmups and a reproducible,
 * position-balanced configuration order. A verification run confirms that
 * each configuration executes all generated tests before measurement.
 *
 * Run the benchmark on an idle machine. Record the parameters with the
 * results in docs/benchmarks.md so that other users can reproduce them.
 *
 * Usage:
 *   php tools/benchmark.php [--shape=<name>] [--scale=<n>] [--workers=<k>]
 *                           [--warmups=<w>] [--runs=<r>] [--seed=<s>]
 *                           [--pause-ms=<n>] [--format=<table|json>]
 *                           [--with-comparisons]
 *
 * Omit --shape to run all shapes.
 */

const PHPUNIT_VERSION = '13.3.0';
const PARATEST_VERSION = '7.24.0';
const PEST_VERSION = '5.1.1';
const BENCHMARK_DEFAULT_SEED = 2_026_082_1;

const BENCHMARK_SHAPES = [
    'many-fast',
    'few-slow',
    'giant-dataset',
    'mixed',
    'many-isolated',
    'resource-constrained',
    'skewed-bootstrap',
    'chatty-diagnostics',
    'coverage-heavy',
];

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;

if (\is_string($scriptFilename) && \realpath($scriptFilename) === __FILE__) {
    exit(\benchmarkMain());
}

function benchmarkMain(): int
{
    try {
        $parsed = \getopt('', [
            'shape:',
            'scale:',
            'workers:',
            'warmups:',
            'runs:',
            'seed:',
            'pause-ms:',
            'format:',
            'with-comparisons',
            'with-phpunit',
        ]);

        if ($parsed === false) {
            throw new InvalidArgumentException('Greenlight cannot parse the benchmark options.');
        }

        $options = \benchmarkParseOptions($parsed);
        $root = \dirname(__DIR__);
        $results = [];
        $comparisonPackages = [];

        \fwrite(\STDERR, \sprintf(
            "Benchmark schedule: warm-up rounds %d, sample rounds %d, seed %d, pause %d ms.\n",
            $options['warmups'],
            $options['runs'],
            $options['seed'],
            $options['pauseMs'],
        ));

        foreach ($options['shapes'] as $shape) {
            $project = \sys_get_temp_dir() . '/greenlight-bench-' . $shape . '-' . \bin2hex(\random_bytes(4));

            try {
                $tests = \benchmarkGenerateShape($shape, $options['scale'], $project);
                \fwrite(\STDERR, \sprintf("[%s] %d tests generated in %s.\n", $shape, $tests, $project));

                $configurations = \benchmarkConfigurations(
                    $shape,
                    $project,
                    $root,
                    $options['workers'],
                    $options['withComparisons'],
                );

                if ($options['withComparisons'] && \benchmarkHasComparisonFixture($shape)) {
                    \benchmarkInstall($project);
                    $installedPackages = \benchmarkInstalledPackages($project);

                    if ($comparisonPackages !== [] && $comparisonPackages !== $installedPackages) {
                        throw new RuntimeException('Composer resolved different comparison package versions between benchmark shapes.');
                    }

                    $comparisonPackages = $installedPackages;
                }

                foreach ($configurations as $id => $configuration) {
                    \fwrite(\STDERR, \sprintf(
                        "[%s] verify: %s.\n",
                        $shape,
                        $configuration['tool'],
                    ));
                    \benchmarkVerifyConfiguration($configuration['command'], $tests, $project, $id);
                }

                $samples = [];

                foreach ($configurations as $id => $_configuration) {
                    $samples[$id] = [];
                }

                foreach (\benchmarkSchedule(\array_keys($configurations), $options['warmups'], $options['seed'], $shape . ':warmup') as $round => $order) {
                    foreach ($order as $id) {
                        \fwrite(\STDERR, \sprintf(
                            "[%s] warm-up %d/%d: %s.\n",
                            $shape,
                            $round + 1,
                            $options['warmups'],
                            $configurations[$id]['tool'],
                        ));
                        \benchmarkTime($configurations[$id]['command']);
                        \benchmarkPause($options['pauseMs']);
                    }
                }

                foreach (\benchmarkSchedule(\array_keys($configurations), $options['runs'], $options['seed'], $shape . ':sample') as $round => $order) {
                    foreach ($order as $id) {
                        \fwrite(\STDERR, \sprintf(
                            "[%s] sample %d/%d: %s.\n",
                            $shape,
                            $round + 1,
                            $options['runs'],
                            $configurations[$id]['tool'],
                        ));
                        $samples[$id][] = \benchmarkTime($configurations[$id]['command']);
                        \benchmarkPause($options['pauseMs']);
                    }
                }

                foreach ($configurations as $id => $configuration) {
                    $results[] = [
                        'shape' => $shape,
                        'tests' => $tests,
                        'tool' => $configuration['tool'],
                        'command' => $configuration['command'],
                        'samplesSeconds' => $samples[$id],
                        ...\benchmarkDistribution($samples[$id]),
                    ];
                }
            } finally {
                \benchmarkRemoveTree($project);
            }
        }

        \benchmarkReport($options, $results, $root, $comparisonPackages);

        return 0;
    } catch (InvalidArgumentException|RuntimeException $error) {
        \fwrite(\STDERR, $error->getMessage() . "\n");

        return 1;
    }
}

/**
 * @param array<string, string|array<mixed>|false> $options
 *
 * @return array{
 *   shapes: list<string>,
 *   scale: positive-int,
 *   workers: positive-int,
 *   warmups: non-negative-int,
 *   runs: positive-int,
 *   seed: int,
 *   pauseMs: int<0, 60000>,
 *   format: 'table'|'json',
 *   withComparisons: bool
 * }
 */
function benchmarkParseOptions(array $options): array
{
    $shape = \benchmarkOptionValue($options, 'shape');
    $shapes = $shape === null ? BENCHMARK_SHAPES : [$shape];

    foreach ($shapes as $selected) {
        if (!\in_array($selected, BENCHMARK_SHAPES, true)) {
            throw new InvalidArgumentException(\sprintf(
                'Unknown benchmark shape "%s". Use one of: %s.',
                $selected,
                \implode(', ', BENCHMARK_SHAPES),
            ));
        }
    }

    $format = \benchmarkOptionValue($options, 'format') ?? 'table';

    if (!\in_array($format, ['table', 'json'], true)) {
        throw new InvalidArgumentException(\sprintf('Option --format must be "table" or "json", got "%s".', $format));
    }

    $pauseMs = \benchmarkNonNegativeIntegerOption($options, 'pause-ms', 100);

    if ($pauseMs > 60_000) {
        throw new InvalidArgumentException(\sprintf('Option --pause-ms must be at most 60000, got %d.', $pauseMs));
    }

    return [
        'shapes' => $shapes,
        'scale' => \benchmarkPositiveIntegerOption($options, 'scale', 10),
        'workers' => \benchmarkPositiveIntegerOption($options, 'workers', 4),
        'warmups' => \benchmarkNonNegativeIntegerOption($options, 'warmups', 2),
        'runs' => \benchmarkPositiveIntegerOption($options, 'runs', 12),
        'seed' => \benchmarkIntegerOption($options, 'seed', BENCHMARK_DEFAULT_SEED),
        'pauseMs' => $pauseMs,
        'format' => $format,
        'withComparisons' => \array_key_exists('with-comparisons', $options)
            || \array_key_exists('with-phpunit', $options),
    ];
}

/** @param array<string, string|array<mixed>|false> $options */
function benchmarkOptionValue(array $options, string $name): ?string
{
    if (!\array_key_exists($name, $options)) {
        return null;
    }

    $value = $options[$name];

    if (!\is_string($value)) {
        throw new InvalidArgumentException(\sprintf('Specify option --%s exactly once with a value.', $name));
    }

    return $value;
}

/** @param array<string, string|array<mixed>|false> $options */
function benchmarkIntegerOption(array $options, string $name, int $default): int
{
    $value = \benchmarkOptionValue($options, $name);

    if ($value === null) {
        return $default;
    }

    if (\preg_match('/^-?\d+$/D', $value) !== 1) {
        throw new InvalidArgumentException(\sprintf('Option --%s must be an integer, got "%s".', $name, $value));
    }

    return (int) $value;
}

/**
 * @param array<string, string|array<mixed>|false> $options
 *
 * @return positive-int
 */
function benchmarkPositiveIntegerOption(array $options, string $name, int $default): int
{
    $parsed = \benchmarkIntegerOption($options, $name, $default);

    if ($parsed < 1) {
        throw new InvalidArgumentException(\sprintf('Option --%s must be at least 1, got %d.', $name, $parsed));
    }

    return $parsed;
}

/**
 * @param array<string, string|array<mixed>|false> $options
 *
 * @return non-negative-int
 */
function benchmarkNonNegativeIntegerOption(array $options, string $name, int $default): int
{
    $parsed = \benchmarkIntegerOption($options, $name, $default);

    if ($parsed < 0) {
        throw new InvalidArgumentException(\sprintf('Option --%s must be at least 0, got %d.', $name, $parsed));
    }

    return $parsed;
}

/**
 * @param list<string> $configurationIds
 *
 * @return list<list<string>>
 */
function benchmarkSchedule(array $configurationIds, int $rounds, int $seed, string $context): array
{
    if ($configurationIds === [] || $rounds < 1) {
        return [];
    }

    $base = $configurationIds;
    \usort($base, static function (string $left, string $right) use ($seed, $context): int {
        $leftHash = \hash('sha256', $seed . "\0" . $context . "\0" . $left);
        $rightHash = \hash('sha256', $seed . "\0" . $context . "\0" . $right);

        return [$leftHash, $left] <=> [$rightHash, $right];
    });

    $schedule = [];
    $count = \count($base);

    for ($round = 0; $round < $rounds; ++$round) {
        $rotation = \intdiv($round, 2) % $count;
        $order = [...\array_slice($base, $rotation), ...\array_slice($base, 0, $rotation)];
        $schedule[] = $round % 2 === 0 ? $order : \array_reverse($order);
    }

    return $schedule;
}

/** @return array<string, array{tool: string, command: string}> */
function benchmarkConfigurations(string $shape, string $project, string $root, int $workers, bool $withComparisons): array
{
    $environment = $shape === 'coverage-heavy' ? 'XDEBUG_MODE=coverage ' : '';
    $greenlight = \sprintf(
        'cd %s && %s%s %s run --workers=%%d --reporter=plain',
        \escapeshellarg($project),
        $environment,
        \escapeshellarg(\PHP_BINARY),
        \escapeshellarg($root . '/bin/greenlight'),
    );
    $configurations = [
        'greenlight-parallel' => [
            'tool' => \sprintf('greenlight (workers=%d)', $workers),
            'command' => \sprintf($greenlight, $workers),
        ],
        'greenlight-one' => [
            'tool' => 'greenlight (workers=1)',
            'command' => \sprintf($greenlight, 1),
        ],
    ];

    if (!$withComparisons || !\benchmarkHasComparisonFixture($shape)) {
        return $configurations;
    }

    $configurations['phpunit'] = [
        'tool' => 'phpunit',
        'command' => \sprintf(
            'cd %s && %s vendor/bin/phpunit --cache-directory=.benchmark-cache/phpunit',
            \escapeshellarg($project),
            \escapeshellarg(\PHP_BINARY),
        ),
    ];
    $configurations['paratest'] = [
        'tool' => \sprintf('paratest (p=%d)', $workers),
        'command' => \sprintf(
            'cd %s && %s vendor/bin/paratest -p%d --cache-directory=.benchmark-cache/paratest',
            \escapeshellarg($project),
            \escapeshellarg(\PHP_BINARY),
            $workers,
        ),
    ];
    $configurations['pest'] = [
        'tool' => 'pest',
        'command' => \sprintf(
            'cd %s && %s vendor/bin/pest --configuration=pest.xml --cache-directory=.benchmark-cache/pest',
            \escapeshellarg($project),
            \escapeshellarg(\PHP_BINARY),
        ),
    ];
    $configurations['pest-parallel'] = [
        'tool' => \sprintf('pest (p=%d)', $workers),
        'command' => \sprintf(
            'cd %s && %s vendor/bin/pest --configuration=pest.xml --parallel --processes=%d --cache-directory=.benchmark-cache/pest-parallel',
            \escapeshellarg($project),
            \escapeshellarg(\PHP_BINARY),
            $workers,
        ),
    ];

    return $configurations;
}

function benchmarkHasComparisonFixture(string $shape): bool
{
    return \in_array($shape, ['many-fast', 'few-slow', 'giant-dataset', 'mixed'], true);
}

function benchmarkTime(string $command): float
{
    $started = \hrtime(true);
    $ignored = [];
    \exec($command . ' >/dev/null 2>&1', $ignored, $exit);
    $seconds = (\hrtime(true) - $started) / 1_000_000_000;

    if ($exit !== 0) {
        throw new RuntimeException(\sprintf('Command failed with exit %d: %s.', $exit, $command));
    }

    return $seconds;
}

function benchmarkPause(int $milliseconds): void
{
    if ($milliseconds > 0) {
        \usleep($milliseconds * 1_000);
    }
}

function benchmarkVerifyConfiguration(string $command, int $expectedTests, string $project, string $id): void
{
    $proof = $project . '/proof-' . $id;

    if (!\mkdir($proof)) {
        throw new RuntimeException('Greenlight cannot create the benchmark proof directory.');
    }

    try {
        $output = [];
        \exec(\sprintf(
            'BENCHMARK_PROOF_DIRECTORY=%s /bin/sh -c %s 2>&1',
            \escapeshellarg($proof),
            \escapeshellarg($command),
        ), $output, $exit);

        if ($exit !== 0) {
            throw new RuntimeException(\sprintf(
                "Verification command failed with exit %d: %s.\n%s",
                $exit,
                $command,
                \implode("\n", $output),
            ));
        }

        $entries = \glob($proof . '/*');

        if ($entries === false) {
            throw new RuntimeException('Greenlight cannot read the benchmark proof directory.');
        }

        if (\count($entries) !== $expectedTests) {
            throw new RuntimeException(\sprintf(
                'Configuration "%s" executed %d of %d generated tests.',
                $id,
                \count($entries),
                $expectedTests,
            ));
        }
    } finally {
        \benchmarkRemoveTree($proof);
    }
}

/**
 * @param list<float> $samples
 *
 * @return array{firstQuartile: float, median: float, thirdQuartile: float, relativeMadPercent: float}
 */
function benchmarkDistribution(array $samples): array
{
    if ($samples === []) {
        throw new RuntimeException('The benchmark distribution needs at least one sample.');
    }

    \sort($samples);
    $count = \count($samples);
    $middle = \intdiv($count, 2);
    $median = \benchmarkMedian($samples);
    $lower = \array_slice($samples, 0, $middle);
    $upper = \array_slice($samples, $count % 2 === 0 ? $middle : $middle + 1);
    $deviations = \array_map(static fn(float $sample): float => \abs($sample - $median), $samples);

    return [
        'firstQuartile' => $lower === [] ? $median : \benchmarkMedian($lower),
        'median' => $median,
        'thirdQuartile' => $upper === [] ? $median : \benchmarkMedian($upper),
        'relativeMadPercent' => $median > 0.0 ? (\benchmarkMedian($deviations) / $median) * 100 : 0.0,
    ];
}

/** @param non-empty-list<float> $samples */
function benchmarkMedian(array $samples): float
{
    \sort($samples);
    $count = \count($samples);
    $middle = \intdiv($count, 2);

    return $count % 2 === 0
        ? ($samples[$middle - 1] + $samples[$middle]) / 2
        : $samples[$middle];
}

/**
 * @param array{
 *   shapes: list<string>,
 *   scale: positive-int,
 *   workers: positive-int,
 *   warmups: non-negative-int,
 *   runs: positive-int,
 *   seed: int,
 *   pauseMs: int<0, 60000>,
 *   format: 'table'|'json',
 *   withComparisons: bool
 * } $options
 * @param list<array{
 *   shape: string,
 *   tests: int,
 *   tool: string,
 *   command: string,
 *   samplesSeconds: list<float>,
 *   firstQuartile: float,
 *   median: float,
 *   thirdQuartile: float,
 *   relativeMadPercent: float
 * }> $results
 * @param array<string, non-empty-string> $comparisonPackages
 */
function benchmarkReport(array $options, array $results, string $root, array $comparisonPackages): void
{
    $environment = \benchmarkEnvironment($root);

    if ($options['format'] === 'json') {
        try {
            echo \json_encode([
                'schemaVersion' => 1,
                'environment' => $environment,
                'parameters' => $options,
                'comparisonVersions' => $options['withComparisons'] ? [
                    'phpunit' => PHPUNIT_VERSION,
                    'paratest' => PARATEST_VERSION,
                    'pest' => PEST_VERSION,
                ] : new stdClass(),
                'comparisonPackages' => $comparisonPackages === [] ? new stdClass() : $comparisonPackages,
                'results' => $results,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $error) {
            throw new RuntimeException('Greenlight cannot encode the benchmark report.', $error->getCode(), previous: $error);
        }

        return;
    }

    echo \sprintf(
        "Environment: PHP %s | %s | extensions: %s.\n",
        $environment['phpVersion'],
        $environment['platform'],
        $environment['measurementExtensions'] === [] ? 'none' : \implode(', ', $environment['measurementExtensions']),
    );
    echo \sprintf(
        "Source revision: %s%s.\n",
        $environment['sourceRevision'] ?? 'unknown',
        $environment['sourceTreeDirty'] === true ? ' (dirty)' : '',
    );

    if ($options['withComparisons']) {
        echo \sprintf(
            "Comparison tools: PHPUnit %s, ParaTest %s, Pest %s.\n",
            PHPUNIT_VERSION,
            PARATEST_VERSION,
            PEST_VERSION,
        );
    }
    echo \sprintf(
        "%-20s %6s  %-24s %7s %8s %8s %8s %8s\n",
        'shape',
        'tests',
        'tool',
        'samples',
        'q1',
        'median',
        'q3',
        'rMAD',
    );

    foreach ($results as $row) {
        echo \sprintf(
            "%-20s %6d  %-24s %7d %7.3fs %7.3fs %7.3fs %7.1f%%\n",
            $row['shape'],
            $row['tests'],
            $row['tool'],
            \count($row['samplesSeconds']),
            $row['firstQuartile'],
            $row['median'],
            $row['thirdQuartile'],
            $row['relativeMadPercent'],
        );
    }
}

/**
 * @return array{
 *   measuredAtUtc: string,
 *   phpVersion: string,
 *   phpBinary: string,
 *   platform: string,
 *   sourceRevision: non-empty-string|null,
 *   sourceTreeDirty: bool|null,
 *   measurementExtensions: list<string>
 * }
 */
function benchmarkEnvironment(string $root): array
{
    $extensions = \array_values(\array_filter(
        ['blackfire', 'ddtrace', 'pcov', 'xdebug', 'Zend OPcache'],
        \extension_loaded(...),
    ));
    $revisionOutput = [];
    \exec(\sprintf('git -C %s rev-parse HEAD 2>/dev/null', \escapeshellarg($root)), $revisionOutput, $revisionExit);
    $revision = $revisionExit === 0 && isset($revisionOutput[0]) && $revisionOutput[0] !== ''
        ? $revisionOutput[0]
        : null;
    $statusOutput = [];
    \exec(\sprintf('git -C %s status --porcelain --untracked-files=no 2>/dev/null', \escapeshellarg($root)), $statusOutput, $statusExit);

    return [
        'measuredAtUtc' => \gmdate('c'),
        'phpVersion' => \PHP_VERSION,
        'phpBinary' => \PHP_BINARY,
        'platform' => \php_uname(),
        'sourceRevision' => $revision,
        'sourceTreeDirty' => $statusExit === 0 ? $statusOutput !== [] : null,
        'measurementExtensions' => $extensions,
    ];
}

function benchmarkGenerateShape(string $shape, int $scale, string $project): int
{
    if (!\mkdir($project . '/tests/gl', 0o777, true)
        || !\mkdir($project . '/tests/pu', 0o777, true)
        || !\mkdir($project . '/tests/pest', 0o777, true)) {
        throw new RuntimeException('Greenlight did not create the benchmark project directory.');
    }

    $tests = match ($shape) {
        // Measures discovery and event costs with many classes and trivial bodies.
        'many-fast' => \benchmarkWriteClasses($project, 'ManyFast', 40 * $scale, 5),
        // Measures scheduler costs with a small number of classes that sleep.
        'few-slow' => \benchmarkWriteClasses($project, 'FewSlow', 8, 4, sleepMicros: 25_000),
        // Measures the indivisible-class limit with one class and many data rows.
        'giant-dataset' => \benchmarkWriteGiantDataSet($project, 100 * $scale),
        'mixed' => \benchmarkWriteClasses($project, 'MixedFast', 20 * $scale, 5)
            + \benchmarkWriteClasses($project, 'MixedSlow', 4, 4, sleepMicros: 25_000)
            + \benchmarkWriteGiantDataSet($project, 40 * $scale),
        // Measures fresh-worker creation for isolated scheduling units.
        'many-isolated' => \benchmarkWriteClasses($project, 'ManyIsolated', 4 * $scale, 1, attribute: "#[Isolated]\n"),
        // Measures work that a resource limit serializes.
        'resource-constrained' => \benchmarkWriteClasses(
            $project,
            'ResourceConstrained',
            4 * $scale,
            1,
            sleepMicros: 10_000,
            attribute: "#[RequiresResource('database')]\n",
        ),
        // Measures scheduling while workers finish bootstrap at different times.
        'skewed-bootstrap' => \benchmarkWriteClasses($project, 'SkewedBootstrap', 20 * $scale, 2),
        // Measures capture and protocol costs for worker diagnostics.
        'chatty-diagnostics' => \benchmarkWriteClasses($project, 'ChattyDiagnostics', 10 * $scale, 2, diagnostics: 25),
        // Measures large per-assignment coverage maps.
        'coverage-heavy' => \benchmarkWriteClasses($project, 'CoverageHeavy', 4 * $scale, 2, statements: 100),
        default => throw new InvalidArgumentException(\sprintf('Unknown benchmark shape "%s".', $shape)),
    };

    \benchmarkWriteConfiguration($project, $shape);

    \file_put_contents($project . '/phpunit.xml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit bootstrap="vendor/autoload.php" colors="false">
            <testsuites>
                <testsuite name="bench">
                    <directory>tests/pu</directory>
                </testsuite>
            </testsuites>
        </phpunit>
        XML);

    \file_put_contents($project . '/pest.xml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit bootstrap="vendor/autoload.php" colors="false">
            <testsuites>
                <testsuite name="bench">
                    <directory>tests/pest</directory>
                </testsuite>
            </testsuites>
        </phpunit>
        XML);

    \file_put_contents($project . '/composer.json', <<<'JSON'
        {
            "autoload-dev": {
                "psr-4": {
                    "Bench\\": "tests/pu/"
                }
            },
            "config": {
                "allow-plugins": {
                    "pestphp/pest-plugin": true
                }
            }
        }
        JSON);

    return $tests;
}

function benchmarkWriteConfiguration(string $project, string $shape): void
{
    $definitions = '';
    $chain = '';

    if ($shape === 'resource-constrained') {
        $chain = "\n    ->resourceLimit('database')";
    } elseif ($shape === 'coverage-heavy') {
        $chain = "\n    ->coverage(fn(CoverageBuilder \$coverage) => \$coverage->include(__DIR__ . '/tests/gl'))";
    } elseif ($shape === 'skewed-bootstrap') {
        $definitions = <<<'PHP'

        final class BenchmarkBootstrapPlugin implements WorkerBootstrapSubscriber
        {
            public function onWorkerBootstrap(WorkerBootstrapContext $context): void
            {
                \usleep($context->channel->number * 15_000);
            }
        }

        PHP;
        $chain = "\n    ->plugins(static fn(): BenchmarkBootstrapPlugin => new BenchmarkBootstrapPlugin())";
    }

    \file_put_contents($project . '/greenlight.php', \sprintf(<<<'PHP'
        <?php

        declare(strict_types=1);

        use Greenlight\Config\CoverageBuilder;
        use Greenlight\Config\GreenlightConfig;
        use Greenlight\Plugin\WorkerBootstrapContext;
        use Greenlight\Plugin\WorkerBootstrapSubscriber;
        %s
        foreach (glob(__DIR__ . '/tests/gl/*Test.php') ?: [] as $file) {
            require_once $file;
        }

        return GreenlightConfig::create()
            ->paths([__DIR__ . '/tests/gl'])%s;
        PHP, $definitions, $chain));
}

function benchmarkWriteClasses(
    string $project,
    string $prefix,
    int $classes,
    int $methods,
    int $sleepMicros = 0,
    string $attribute = '',
    int $diagnostics = 0,
    int $statements = 0,
): int {
    for ($i = 0; $i < $classes; ++$i) {
        $name = \sprintf('%s%04dTest', $prefix, $i);
        $glBody = '';
        $puBody = '';
        $pestBody = '';

        for ($m = 0; $m < $methods; ++$m) {
            $proofWork = \sprintf(<<<'PHP'
                if (($proof = \getenv('BENCHMARK_PROOF_DIRECTORY')) !== false) {
                    \touch($proof . '/%s-%04d-%04d');
                }

                PHP, \strtolower($prefix), $i, $m);
            $sleepWork = $sleepMicros > 0 ? \sprintf("\\usleep(%d);\n        ", $sleepMicros) : '';
            $glWork = $proofWork . $sleepWork;
            $puWork = $glWork;
            $pestWork = $glWork;

            if ($diagnostics > 0) {
                $diagnosticWork = \sprintf(
                    "for (\$diagnostic = 0; \$diagnostic < %d; ++\$diagnostic) {\n            \\trigger_error('Benchmark diagnostic.', \\E_USER_NOTICE);\n        }\n        ",
                    $diagnostics,
                );
                $glWork .= $diagnosticWork;
                $puWork .= $diagnosticWork;
                $pestWork .= $diagnosticWork;
            }

            if ($statements > 0) {
                $glWork .= \sprintf("\$value = %d;\n        ", $m);
                $puWork .= \sprintf("\$value = %d;\n        ", $m);
                $pestWork .= \sprintf("\$value = %d;\n        ", $m);

                for ($statement = 0; $statement < $statements; ++$statement) {
                    $glWork .= "\$value += 1;\n        ";
                    $puWork .= "\$value += 1;\n        ";
                    $pestWork .= "\$value += 1;\n        ";
                }

                $glExpectation = \sprintf('Expect::that($value)->toBe(%d);', $m + $statements);
                $puExpectation = \sprintf('$this->assertSame(%d, $value);', $m + $statements);
                $pestExpectation = \sprintf('expect($value)->toBe(%d);', $m + $statements);
            } else {
                $glExpectation = \sprintf('Expect::that(%d + 1)->toBe(%d);', $m, $m + 1);
                $puExpectation = \sprintf('$this->assertSame(%d, %d + 1);', $m + 1, $m);
                $pestExpectation = \sprintf('expect(%d + 1)->toBe(%d);', $m, $m + 1);
            }

            $glBody .= \sprintf(<<<'PHP'

                    #[Test]
                    public function case%d(): void
                    {
                        %s%s
                    }

                PHP, $m, $glWork, $glExpectation);
            $puBody .= \sprintf(<<<'PHP'

                    public function testCase%d(): void
                    {
                        %s%s
                    }

                PHP, $m, $puWork, $puExpectation);
            $pestBody .= \sprintf(<<<'PHP'

                test('case %d', function (): void {
                    %s%s
                })%s

                PHP, $m, $pestWork, $pestExpectation, ';');
        }

        \file_put_contents($project . '/tests/gl/' . $name . '.php', \sprintf(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Bench;

            use Greenlight\Attribute\Isolated;
            use Greenlight\Attribute\RequiresResource;
            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;

            %sfinal class %s
            {%s}

            PHP, $attribute, $name, $glBody));

        \file_put_contents($project . '/tests/pu/' . $name . '.php', \sprintf(<<<'PHP_WRAP'
        <?php

        declare(strict_types=1);

        namespace Bench;

        use PHPUnit\Framework\TestCase;

        final class %s extends TestCase
        {
        %s}

        PHP_WRAP, $name, $puBody));

        \file_put_contents($project . '/tests/pest/' . $name . '.php', \sprintf(<<<'PHP_WRAP'
        <?php

        declare(strict_types=1);
        %s
        PHP_WRAP, $pestBody));
    }

    return $classes * $methods;
}

function benchmarkWriteGiantDataSet(string $project, int $rows): int
{
    \file_put_contents($project . '/tests/gl/GiantTest.php', \sprintf(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Bench;

        use Greenlight\Attribute\DataSet;
        use Greenlight\Attribute\Test;
        use Greenlight\Expect\Expect;

        final class GiantTest
        {
            #[Test]
            #[DataSet('rows')]
            public function handles(int $value): void
            {
                if (($proof = \getenv('BENCHMARK_PROOF_DIRECTORY')) !== false) {
                    \touch($proof . '/giant-' . $value);
                }

                Expect::that($value)->toBeGreaterThan(-1);
            }

            /** @return iterable<string, array{int}> */
            public static function rows(): iterable
            {
                for ($i = 0; $i < %d; ++$i) {
                    yield 'row ' . $i => [$i];
                }
            }
        }

        PHP, $rows));

    \file_put_contents($project . '/tests/pu/GiantTest.php', \sprintf(<<<'PHP_WRAP'
    <?php

    declare(strict_types=1);

    namespace Bench;

    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class GiantTest extends TestCase
    {
        #[DataProvider('rows')]
        public function testHandles(int $value): void
        {
            if (($proof = \getenv('BENCHMARK_PROOF_DIRECTORY')) !== false) {
                \touch($proof . '/giant-' . $value);
            }

            $this->assertGreaterThan(-1, $value);
        }

        /** @return iterable<string, array{int}> */
        public static function rows(): iterable
        {
            for ($i = 0; $i < %d; ++$i) {
                yield 'row ' . $i => [$i];
            }
        }
    }

    PHP_WRAP, $rows));

    \file_put_contents($project . '/tests/pest/GiantTest.php', \sprintf(<<<'PHP'
        <?php

        declare(strict_types=1);

        test('handles', function (int $value): void {
            if (($proof = \getenv('BENCHMARK_PROOF_DIRECTORY')) !== false) {
                \touch($proof . '/giant-' . $value);
            }

            expect($value)->toBeGreaterThan(-1);
        })->with((static function (): iterable {
            for ($i = 0; $i < %d; ++$i) {
                yield 'row ' . $i => [$i];
            }
        })());

        PHP, $rows));

    return $rows;
}

function benchmarkInstall(string $project): void
{
    $output = [];
    \exec(\sprintf(
        'cd %s && composer require --dev --quiet --no-interaction %s %s %s 2>&1',
        \escapeshellarg($project),
        \escapeshellarg('phpunit/phpunit:' . PHPUNIT_VERSION),
        \escapeshellarg('brianium/paratest:' . PARATEST_VERSION),
        \escapeshellarg('pestphp/pest:' . PEST_VERSION),
    ), $output, $exit);

    if ($exit !== 0) {
        throw new RuntimeException("Composer did not install the comparison tools:\n" . \implode("\n", $output));
    }
}

/** @return array<string, non-empty-string> */
function benchmarkInstalledPackages(string $project): array
{
    $installedFile = $project . '/vendor/composer/installed.php';

    if (!\is_file($installedFile)) {
        throw new RuntimeException('Composer did not create the comparison package metadata.');
    }

    $installed = require $installedFile;

    if (!\is_array($installed) || !isset($installed['versions']) || !\is_array($installed['versions'])) {
        throw new RuntimeException('Composer created invalid comparison package metadata.');
    }

    $versions = [];

    foreach ($installed['versions'] as $package => $metadata) {
        if (!\is_string($package) || $package === '__root__' || !\is_array($metadata)) {
            continue;
        }

        $version = $metadata['pretty_version'] ?? null;

        if (\is_string($version) && $version !== '') {
            $versions[$package] = $version;
        }
    }

    \ksort($versions);

    return $versions;
}

function benchmarkRemoveTree(string $directory): void
{
    if (!\is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo) {
            throw new RuntimeException('Greenlight cannot read a benchmark temporary-directory entry.');
        }

        if ($entry->isDir() && !$entry->isLink()) {
            \rmdir($entry->getPathname());
        } else {
            \unlink($entry->getPathname());
        }
    }

    \rmdir($directory);
}
