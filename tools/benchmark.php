<?php

declare(strict_types=1);

/**
 * Benchmark harness: generates synthetic suites in the shapes that matter,
 * runs each under Greenlight (and under PHPUnit and ParaTest when
 * --with-phpunit is given, installed into the generated project via
 * composer), and reports median wall times. Results are only meaningful on
 * an idle machine; docs/benchmarks.md records the parameters alongside the
 * numbers so anyone can reproduce them.
 *
 * Usage:
 *   php tools/benchmark.php [--shape=<name>] [--scale=<n>] [--workers=<k>]
 *                           [--runs=<r>] [--with-phpunit]
 *
 * Shapes: many-fast, few-slow, giant-dataset, mixed (default: all).
 */

$options = getopt('', ['shape:', 'scale:', 'workers:', 'runs:', 'with-phpunit']);
$shapes = isset($options['shape']) && is_string($options['shape'])
    ? [$options['shape']]
    : ['many-fast', 'few-slow', 'giant-dataset', 'mixed'];
$scale = isset($options['scale']) && is_string($options['scale']) ? max(1, (int) $options['scale']) : 10;
$workers = isset($options['workers']) && is_string($options['workers']) ? max(1, (int) $options['workers']) : 4;
$runs = isset($options['runs']) && is_string($options['runs']) ? max(1, (int) $options['runs']) : 3;
$withPhpunit = array_key_exists('with-phpunit', $options);

$root = dirname(__DIR__);
$results = [];

foreach ($shapes as $shape) {
    $project = sys_get_temp_dir() . '/greenlight-bench-' . $shape . '-' . bin2hex(random_bytes(4));
    $tests = generateShape($shape, $scale, $project);
    fwrite(STDERR, sprintf("[%s] %d tests generated in %s\n", $shape, $tests, $project));

    $results[] = [
        'shape' => $shape,
        'tests' => $tests,
        'tool' => sprintf('greenlight (workers=%d)', $workers),
        'seconds' => median(times($runs, sprintf(
            'cd %s && %s %s run --workers=%d --reporter=plain',
            escapeshellarg($project),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/bin/greenlight'),
            $workers,
        ))),
    ];

    $results[] = [
        'shape' => $shape,
        'tests' => $tests,
        'tool' => 'greenlight (workers=1)',
        'seconds' => median(times($runs, sprintf(
            'cd %s && %s %s run --workers=1 --reporter=plain',
            escapeshellarg($project),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/bin/greenlight'),
        ))),
    ];

    if ($withPhpunit) {
        install($project);

        $results[] = [
            'shape' => $shape,
            'tests' => $tests,
            'tool' => 'phpunit',
            'seconds' => median(times($runs, sprintf(
                'cd %s && %s vendor/bin/phpunit --no-progress --no-output',
                escapeshellarg($project),
                escapeshellarg(PHP_BINARY),
            ))),
        ];

        $results[] = [
            'shape' => $shape,
            'tests' => $tests,
            'tool' => sprintf('paratest (p=%d)', $workers),
            'seconds' => median(times($runs, sprintf(
                'cd %s && %s vendor/bin/paratest -p%d 2>&1',
                escapeshellarg($project),
                escapeshellarg(PHP_BINARY),
                $workers,
            ))),
        ];
    }

    removeTree($project);
}

echo sprintf("%-14s %6s  %-24s %8s\n", 'shape', 'tests', 'tool', 'median');

foreach ($results as $row) {
    echo sprintf("%-14s %6d  %-24s %7.3fs\n", $row['shape'], $row['tests'], $row['tool'], $row['seconds']);
}

exit(0);

/**
 * @return list<float>
 */
function times(int $runs, string $command): array
{
    $samples = [];

    for ($i = 0; $i < $runs; ++$i) {
        $started = hrtime(true);
        exec($command . ' >/dev/null 2>&1', $ignored, $exit);
        $seconds = (hrtime(true) - $started) / 1_000_000_000;

        if ($exit !== 0) {
            fwrite(STDERR, sprintf("Command failed (exit %d): %s\n", $exit, $command));
            exit(1);
        }

        $samples[] = $seconds;
    }

    return $samples;
}

/**
 * @param list<float> $samples
 */
function median(array $samples): float
{
    sort($samples);

    return $samples[intdiv(count($samples), 2)] ?? 0.0;
}

function generateShape(string $shape, int $scale, string $project): int
{
    if (!mkdir($project . '/tests/gl', 0o777, true) || !mkdir($project . '/tests/pu', 0o777, true)) {
        fwrite(STDERR, "Could not create the benchmark project directory.\n");
        exit(1);
    }

    $tests = match ($shape) {
        // Discovery- and event-bound: lots of classes, trivial bodies.
        'many-fast' => writeClasses($project, 'ManyFast', 40 * $scale, 5, 0),
        // Scheduler-bound: a handful of classes dominated by sleep.
        'few-slow' => writeClasses($project, 'FewSlow', 8, 4, 25_000),
        // The indivisible-class worst case: one class, many data rows.
        'giant-dataset' => writeGiantDataSet($project, 100 * $scale),
        'mixed' => writeClasses($project, 'MixedFast', 20 * $scale, 5, 0)
            + writeClasses($project, 'MixedSlow', 4, 4, 25_000)
            + writeGiantDataSet($project, 40 * $scale),
        default => -1,
    };

    if ($tests < 0) {
        fwrite(STDERR, sprintf("Unknown shape \"%s\".\n", $shape));
        exit(1);
    }

    file_put_contents($project . '/greenlight.php', <<<PHP
        <?php

        declare(strict_types=1);

        use Greenlight\\Config\\GreenlightConfig;

        foreach (glob(__DIR__ . '/tests/gl/*Test.php') ?: [] as \$file) {
            require_once \$file;
        }

        return GreenlightConfig::create()->paths([__DIR__ . '/tests/gl']);
        PHP);

    file_put_contents($project . '/phpunit.xml', <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit bootstrap="vendor/autoload.php" colors="false">
            <testsuites>
                <testsuite name="bench">
                    <directory>tests/pu</directory>
                </testsuite>
            </testsuites>
        </phpunit>
        XML);

    file_put_contents($project . '/composer.json', <<<JSON
        {
            "autoload-dev": {
                "psr-4": {
                    "Bench\\\\": "tests/pu/"
                }
            }
        }
        JSON);

    return $tests;
}

function writeClasses(string $project, string $prefix, int $classes, int $methods, int $sleepMicros): int
{
    for ($i = 0; $i < $classes; ++$i) {
        $name = sprintf('%s%04dTest', $prefix, $i);
        $glBody = '';
        $puBody = '';

        for ($m = 0; $m < $methods; ++$m) {
            $work = $sleepMicros > 0 ? sprintf("\\usleep(%d);\n        ", $sleepMicros) : '';
            $glBody .= sprintf(<<<'PHP'

                    #[Test]
                    public function case%d(): void
                    {
                        %sExpect::that(%d + 1)->toBe(%d);
                    }

                PHP, $m, $work, $m, $m + 1);
            $puBody .= sprintf(<<<'PHP'

                    public function testCase%d(): void
                    {
                        %s$this->assertSame(%d, %d + 1);
                    }

                PHP, $m, $work, $m + 1, $m);
        }

        file_put_contents($project . '/tests/gl/' . $name . '.php', sprintf(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Bench;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;

            final class %s
            {%s}

            PHP, $name, $glBody));

        file_put_contents($project . '/tests/pu/' . $name . '.php', sprintf(<<<'PHP_WRAP'
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

function writeGiantDataSet(string $project, int $rows): int
{
    file_put_contents($project . '/tests/gl/GiantTest.php', sprintf(<<<'PHP'
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

    file_put_contents($project . '/tests/pu/GiantTest.php', sprintf(<<<'PHP_WRAP'
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

function install(string $project): void
{
    exec(sprintf(
        'cd %s && composer require --dev --quiet --no-interaction phpunit/phpunit brianium/paratest 2>&1',
        escapeshellarg($project),
    ), $output, $exit);

    if ($exit !== 0) {
        fwrite(STDERR, "composer install of phpunit/paratest failed:\n" . implode("\n", $output) . "\n");
        exit(1);
    }
}

function removeTree(string $directory): void
{
    exec(sprintf('rm -rf %s', escapeshellarg($directory)));
}
