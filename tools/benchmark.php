<?php

declare(strict_types=1);

/**
 * Generates synthetic suites and reports wall-time distributions for each
 * benchmark shape. The schedule uses an explicit warm-up and a reproducible
 * configuration order.
 *
 * Run the benchmark on an idle machine. Record the parameters with the
 * results in docs/benchmarks.md so that other users can reproduce them.
 *
 * Usage:
 *   php tools/benchmark.php [--shape=<name>] [--scale=<n>] [--workers=<k>]
 *                           [--warmups=<w>] [--runs=<r>] [--seed=<s>]
 *                           [--with-phpunit]
 *
 * Omit --shape to run all shapes.
 */

const PHPUNIT_VERSION = '13.2.3';
const PARATEST_VERSION = '7.23.0';
const BENCHMARK_DEFAULT_SEED = 2_026_082_1;

const BENCHMARK_SHAPES = [
    'many-fast',
    'few-slow',
    'giant-dataset',
    'mixed',
    'many-isolated',
    'recycle-one',
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
            'with-phpunit',
        ]);

        if ($parsed === false) {
            throw new InvalidArgumentException('Greenlight cannot parse the benchmark options.');
        }

        $options = \benchmarkParseOptions($parsed);
        $root = \dirname(__DIR__);
        $results = [];

        \fwrite(\STDERR, \sprintf(
            "Benchmark schedule: warm-up rounds %d, sample rounds %d, seed %d.\n",
            $options['warmups'],
            $options['runs'],
            $options['seed'],
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
                    $options['withPhpunit'],
                );

                if ($options['withPhpunit'] && \benchmarkHasComparisonFixture($shape)) {
                    \benchmarkInstall($project);
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
                    }
                }

                foreach ($configurations as $id => $configuration) {
                    $results[] = [
                        'shape' => $shape,
                        'tests' => $tests,
                        'tool' => $configuration['tool'],
                        ...\benchmarkDistribution($samples[$id]),
                    ];
                }
            } finally {
                \benchmarkRemoveTree($project);
            }
        }

        echo \sprintf(
            "%-20s %6s  %-24s %8s %8s %8s %8s\n",
            'shape',
            'tests',
            'tool',
            'minimum',
            'median',
            'maximum',
            'spread',
        );

        foreach ($results as $row) {
            echo \sprintf(
                "%-20s %6d  %-24s %7.3fs %7.3fs %7.3fs %7.1f%%\n",
                $row['shape'],
                $row['tests'],
                $row['tool'],
                $row['minimum'],
                $row['median'],
                $row['maximum'],
                $row['spreadPercent'],
            );
        }

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
 *   withPhpunit: bool
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

    return [
        'shapes' => $shapes,
        'scale' => \benchmarkPositiveIntegerOption($options, 'scale', 10),
        'workers' => \benchmarkPositiveIntegerOption($options, 'workers', 4),
        'warmups' => \benchmarkNonNegativeIntegerOption($options, 'warmups', 1),
        'runs' => \benchmarkPositiveIntegerOption($options, 'runs', 3),
        'seed' => \benchmarkIntegerOption($options, 'seed', BENCHMARK_DEFAULT_SEED),
        'withPhpunit' => \array_key_exists('with-phpunit', $options),
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
function benchmarkConfigurations(string $shape, string $project, string $root, int $workers, bool $withPhpunit): array
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

    if (!$withPhpunit || !\benchmarkHasComparisonFixture($shape)) {
        return $configurations;
    }

    $configurations['phpunit'] = [
        'tool' => 'phpunit',
        'command' => \sprintf(
            'cd %s && %s vendor/bin/phpunit --no-progress --no-output',
            \escapeshellarg($project),
            \escapeshellarg(\PHP_BINARY),
        ),
    ];
    $configurations['paratest'] = [
        'tool' => \sprintf('paratest (p=%d)', $workers),
        'command' => \sprintf(
            'cd %s && %s vendor/bin/paratest -p%d 2>&1',
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

/**
 * @param list<float> $samples
 *
 * @return array{minimum: float, median: float, maximum: float, spreadPercent: float}
 */
function benchmarkDistribution(array $samples): array
{
    if ($samples === []) {
        throw new RuntimeException('The benchmark distribution needs at least one sample.');
    }

    \sort($samples);
    $count = \count($samples);
    $middle = \intdiv($count, 2);
    $median = $count % 2 === 0
        ? ($samples[$middle - 1] + $samples[$middle]) / 2
        : $samples[$middle];
    $minimum = $samples[0];
    $maximum = $samples[$count - 1];

    return [
        'minimum' => $minimum,
        'median' => $median,
        'maximum' => $maximum,
        'spreadPercent' => $median > 0.0 ? (($maximum - $minimum) / $median) * 100 : 0.0,
    ];
}

function benchmarkGenerateShape(string $shape, int $scale, string $project): int
{
    if (!\mkdir($project . '/tests/gl', 0o777, true) || !\mkdir($project . '/tests/pu', 0o777, true)) {
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
        // Measures worker replacement after each test.
        'recycle-one' => \benchmarkWriteClasses($project, 'RecycleOne', 4 * $scale, 2),
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

    \file_put_contents($project . '/composer.json', <<<'JSON'
        {
            "autoload-dev": {
                "psr-4": {
                    "Bench\\": "tests/pu/"
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

    if ($shape === 'recycle-one') {
        $chain = "\n    ->workers(recycleAfterTests: 1)";
    } elseif ($shape === 'resource-constrained') {
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
        $chain = "\n    ->plugins(new PluginDefinition(BenchmarkBootstrapPlugin::class, static fn(): BenchmarkBootstrapPlugin => new BenchmarkBootstrapPlugin()))";
    }

    \file_put_contents($project . '/greenlight.php', \sprintf(<<<'PHP'
        <?php

        declare(strict_types=1);

        use Greenlight\Config\CoverageBuilder;
        use Greenlight\Config\GreenlightConfig;
        use Greenlight\Plugin\PluginDefinition;
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

        for ($m = 0; $m < $methods; ++$m) {
            $glWork = $sleepMicros > 0 ? \sprintf("\\usleep(%d);\n        ", $sleepMicros) : '';
            $puWork = $glWork;

            if ($diagnostics > 0) {
                $diagnosticWork = \sprintf(
                    "for (\$diagnostic = 0; \$diagnostic < %d; ++\$diagnostic) {\n            \\trigger_error('Benchmark diagnostic.', \\E_USER_NOTICE);\n        }\n        ",
                    $diagnostics,
                );
                $glWork .= $diagnosticWork;
                $puWork .= $diagnosticWork;
            }

            if ($statements > 0) {
                $glWork .= \sprintf("\$value = %d;\n        ", $m);
                $puWork .= \sprintf("\$value = %d;\n        ", $m);

                for ($statement = 0; $statement < $statements; ++$statement) {
                    $glWork .= "\$value += 1;\n        ";
                    $puWork .= "\$value += 1;\n        ";
                }

                $glExpectation = \sprintf('Expect::that($value)->toBe(%d);', $m + $statements);
                $puExpectation = \sprintf('$this->assertSame(%d, $value);', $m + $statements);
            } else {
                $glExpectation = \sprintf('Expect::that(%d + 1)->toBe(%d);', $m, $m + 1);
                $puExpectation = \sprintf('$this->assertSame(%d, %d + 1);', $m + 1, $m);
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

    return $rows;
}

function benchmarkInstall(string $project): void
{
    $output = [];
    \exec(\sprintf(
        'cd %s && composer require --dev --quiet --no-interaction %s %s 2>&1',
        \escapeshellarg($project),
        \escapeshellarg('phpunit/phpunit:' . PHPUNIT_VERSION),
        \escapeshellarg('brianium/paratest:' . PARATEST_VERSION),
    ), $output, $exit);

    if ($exit !== 0) {
        throw new RuntimeException("Composer did not install PHPUnit and ParaTest:\n" . \implode("\n", $output));
    }
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
