<?php

declare(strict_types=1);

/*
 * The flat-memory gate: generates a 10,000-test project, runs it through
 * bin/greenlight in one worker, samples PHP-visible memory via a probe plugin
 * after a warmup, and fails when late-run memory drifts more than 1 MiB above
 * the post-warmup baseline. Leaks in the framework itself have nowhere to
 * hide across ten thousand tests.
 */

// Few unique methods executed many times through data sets: engine warmup
// (per-method run-time caches) is bounded, so any remaining slope is a
// genuine per-test lifecycle leak.
const CLASS_COUNT = 20;
const METHODS_PER_CLASS = 5;
const ROWS_PER_METHOD = 100;
const WARMUP_TESTS = 2000;
const MAX_DRIFT_BYTES = 1_048_576;

$root = dirname(__DIR__);
$workDir = rtrim(sys_get_temp_dir(), '/') . '/greenlight-memgate-' . bin2hex(random_bytes(4));
$suiteDir = $workDir . '/suite';
$samplesFile = $workDir . '/samples.json';

mkdir($suiteDir, 0o777, true);

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
                \$this->expect->that(strlen(\$payload))->toBe(1024 + \$row);
            }

        PHP;
    }

    $rows = (string) ROWS_PER_METHOD;
    $class = sprintf('Gen%04dTest', $classIndex);
    file_put_contents($suiteDir . '/' . $class . '.php', <<<PHP
    <?php

    declare(strict_types=1);

    namespace MemGate;

    use Greenlight\Attribute\DataSet;
    use Greenlight\Attribute\Test;
    use Greenlight\Expect\Expect;

    final readonly class {$class}
    {
        public function __construct(
            private Expect \$expect,
        ) {}

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

file_put_contents($workDir . '/MemoryProbe.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MemGate;

use Greenlight\Core\Result\TestResult;
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\TestLifecycleSubscriber;

final class MemoryProbe implements TestLifecycleSubscriber
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

    public function beforeTest(TestContext $context): void {}

    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        ++$this->count;

        if ($this->count === $this->warmupTests || $this->count === $this->totalTests) {
            gc_collect_cycles();
            // Real allocation, not reserved allocator chunks: chunk reservation
            // moves in 2 MiB steps and would mask or fake a leak slope.
            $this->samples[(string) $this->count] = memory_get_usage();
            file_put_contents($this->samplesFile, json_encode($this->samples));
        }

        return $result;
    }
}

PHP);

file_put_contents($workDir . '/greenlight.php', <<<PHP
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
    ->plugins(new MemGate\MemoryProbe('{$samplesFile}', {WARMUP}, {TOTAL}));

PHP);

$config = file_get_contents($workDir . '/greenlight.php');
$config = str_replace(['{WARMUP}', '{TOTAL}'], [(string) WARMUP_TESTS, (string) $totalTests], (string) $config);
file_put_contents($workDir . '/greenlight.php', $config);

echo sprintf("Running %d generated tests in one worker...\n", $totalTests);

$command = sprintf(
    'cd %s && %s %s run --reporter=plain 2>&1 | tail -4',
    escapeshellarg($workDir),
    escapeshellarg(PHP_BINARY),
    escapeshellarg($root . '/bin/greenlight'),
);
exec($command, $output, $exitCode);
echo implode("\n", $output) . "\n";

$cleanup = static function () use ($workDir): void {
    exec('rm -rf ' . escapeshellarg($workDir));
};

$samplesJson = is_file($samplesFile) ? file_get_contents($samplesFile) : false;

if ($samplesJson === false) {
    fwrite(STDERR, "The memory probe wrote no samples; the run did not reach the sampling points.\n");
    $cleanup();
    exit(1);
}

$samples = json_decode($samplesJson, true);
$baseline = null;
$final = null;

if (is_array($samples)) {
    $baselineRaw = $samples[(string) WARMUP_TESTS] ?? null;
    $finalRaw = $samples[(string) $totalTests] ?? null;
    $baseline = is_int($baselineRaw) ? $baselineRaw : null;
    $final = is_int($finalRaw) ? $finalRaw : null;
}

if ($baseline === null || $final === null) {
    fwrite(STDERR, "Sampling points are missing from the probe output.\n");
    $cleanup();
    exit(1);
}

$drift = $final - $baseline;

echo sprintf(
    "Memory after %d tests: %.2f MiB; after %d tests: %.2f MiB; drift: %+d bytes (limit %d).\n",
    WARMUP_TESTS,
    $baseline / 1_048_576,
    $totalTests,
    $final / 1_048_576,
    $drift,
    MAX_DRIFT_BYTES,
);

$cleanup();

if ($drift > MAX_DRIFT_BYTES) {
    fwrite(STDERR, "FLAT-MEMORY GATE FAILED: the framework leaks across a long run.\n");
    exit(1);
}

echo "Flat-memory gate passed.\n";
exit(0);
