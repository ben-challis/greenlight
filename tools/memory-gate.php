<?php

declare(strict_types=1);

/*
 * Generates a 10,000-test project and runs it with bin/greenlight in one
 * worker. A probe plugin samples PHP-visible memory after a warmup. The gate
 * fails if late-run memory exceeds the post-warmup baseline by more than
 * 1 MiB. Thus, the gate exposes per-test memory growth in Greenlight.
 */

// A small set of methods runs many times with data sets. Per-method runtime
// caches can stabilize during engine warmup. Growth after warmup can indicate
// a per-test lifecycle leak. The measurements do not identify its cause.
const CLASS_COUNT = 20;
const METHODS_PER_CLASS = 5;
const ROWS_PER_METHOD = 100;
const WARMUP_TESTS = 2000;
const MAX_DRIFT_BYTES = 1_048_576;

$root = \dirname(__DIR__);
$workDir = \rtrim(\sys_get_temp_dir(), '/') . '/greenlight-memgate-' . \bin2hex(\random_bytes(4));
$suiteDir = $workDir . '/suite';
$samplesFile = $workDir . '/samples.json';

if (!\mkdir($suiteDir, 0o777, true) && !\is_dir($suiteDir)) {
    throw new RuntimeException(\sprintf('Greenlight did not create directory "%s".', $suiteDir));
}

$totalTests = CLASS_COUNT * METHODS_PER_CLASS * ROWS_PER_METHOD;

for ($classIndex = 0; $classIndex < CLASS_COUNT; ++$classIndex) {
    $methods = '';

    for ($testIndex = 0; $testIndex < METHODS_PER_CLASS; ++$testIndex) {
        $methods .= <<<PHP

            #[Test]
            #[DataSet('rows')]
            public function t{$testIndex}(int \$row): void
            {
                \$payload = str_repeat('x', 1024 + \$row);
                Expect::that(strlen(\$payload))->toBe(1024 + \$row);
            }

        PHP;
    }

    $rows = (string) ROWS_PER_METHOD;
    $class = \sprintf('Gen%04dTest', $classIndex);
    \file_put_contents($suiteDir . '/' . $class . '.php', <<<PHP
    <?php

    declare(strict_types=1);

    namespace MemGate;

    use Greenlight\Attribute\DataSet;
    use Greenlight\Attribute\Test;
    use Greenlight\Expect\Expect;

    final readonly class {$class}
    {
        /**
         * @return iterable<string, array{int}>
         */
        public static function rows(): iterable
        {
            for (\$i = 0; \$i < {$rows}; ++\$i) {
                yield 'row ' . \$i => [\$i];
            }
        }
    {$methods}}

    PHP);
}

\file_put_contents($workDir . '/MemoryProbe.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MemGate;

use Greenlight\Result\TestResult;
use Greenlight\Plugin\AfterTestSubscriber;
use Greenlight\Plugin\TestContext;

final class MemoryProbe implements AfterTestSubscriber
{
    private int $count = 0;

    /**
     * @var array<string, int>
     */
    private array $samples = [];

    public function __construct(
        private readonly string $samplesFile,
        private readonly int $warmupTests,
        private readonly int $totalTests,
    ) {}

    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        ++$this->count;

        if ($this->count === $this->warmupTests || $this->count === $this->totalTests) {
            gc_collect_cycles();
            // Real allocation, not reserved allocator chunks. Chunk
            // reservation moves in 2 MiB steps. It can hide a leak slope or
            // create a false one.
            $this->samples[(string) $this->count] = memory_get_usage();
            file_put_contents($this->samplesFile, json_encode($this->samples));
        }

        return $result;
    }
}

PHP);

\file_put_contents($workDir . '/greenlight.php', <<<PHP
<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;

spl_autoload_register(static function (string \$class): void {
    if (!str_starts_with(\$class, 'MemGate\\\\')) {
        return;
    }

    \$short = substr(\$class, strlen('MemGate\\\\'));
    foreach ([__DIR__ . '/suite/' . \$short . '.php', __DIR__ . '/' . \$short . '.php'] as \$file) {
        if (is_file(\$file)) {
            require \$file;

            return;
        }
    }
});

return GreenlightConfig::create()
    ->paths([__DIR__ . '/suite'])
    ->workers(count: 1)
    ->plugins(
        static fn(): MemGate\MemoryProbe => new MemGate\MemoryProbe('{$samplesFile}', {WARMUP}, {TOTAL}),
    );

PHP);

$config = \file_get_contents($workDir . '/greenlight.php');
$config = \str_replace(['{WARMUP}', '{TOTAL}'], [(string) WARMUP_TESTS, (string) $totalTests], (string) $config);
\file_put_contents($workDir . '/greenlight.php', $config);

echo \sprintf("Greenlight runs %d generated tests in one worker...\n", $totalTests);

$cleanup = static function () use ($workDir): void {
    \exec('rm -rf ' . \escapeshellarg($workDir));
};

$process = \proc_open(
    [\PHP_BINARY, $root . '/bin/greenlight', 'run', '--reporter=plain'],
    [0 => \STDIN, 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
    $pipes,
    $workDir,
);

if (!\is_resource($process)) {
    $cleanup();
    throw new RuntimeException('Greenlight could not start the generated test run.');
}

$output = [];

while (($line = \fgets($pipes[1])) !== false) {
    $output[] = \rtrim($line, "\r\n");

    if (\count($output) > 4) {
        \array_shift($output);
    }
}

\fclose($pipes[1]);
$exitCode = \proc_close($process);
echo \implode("\n", $output) . "\n";

$samplesJson = \is_file($samplesFile) ? \file_get_contents($samplesFile) : false;

if ($samplesJson === false) {
    \fwrite(\STDERR, "The memory probe wrote no samples. The run did not reach the sample points.\n");
    $cleanup();
    exit(1);
}

$samples = \json_decode($samplesJson, true, 512, \JSON_THROW_ON_ERROR);
$baseline = null;
$final = null;

if (\is_array($samples)) {
    $baselineRaw = $samples[(string) WARMUP_TESTS] ?? null;
    $finalRaw = $samples[(string) $totalTests] ?? null;
    $baseline = \is_int($baselineRaw) ? $baselineRaw : null;
    $final = \is_int($finalRaw) ? $finalRaw : null;
}

if ($baseline === null || $final === null) {
    \fwrite(\STDERR, "The probe output does not contain all sample points.\n");
    $cleanup();
    exit(1);
}

if ($exitCode !== 0) {
    \fwrite(\STDERR, \sprintf("The generated test run failed with exit code %d.\n", $exitCode));
    $cleanup();
    exit(1);
}

$drift = $final - $baseline;

echo \sprintf(
    "Memory after %d tests: %.2f MiB. Memory after %d tests: %.2f MiB. Drift: %+d bytes. Limit: %d bytes.\n",
    WARMUP_TESTS,
    $baseline / 1_048_576,
    $totalTests,
    $final / 1_048_576,
    $drift,
    MAX_DRIFT_BYTES,
);

$cleanup();

if ($drift > MAX_DRIFT_BYTES) {
    \fwrite(\STDERR, "Flat-memory gate failed: measured memory growth exceeds the limit.\n");
    exit(1);
}

echo "Flat-memory gate passed.\n";
exit(0);
