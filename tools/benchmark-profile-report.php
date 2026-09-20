<?php

declare(strict_types=1);

namespace Greenlight\Tools;

/**
 * Measures profile-report time and peak memory with a synthetic JSONL stream.
 * Run: php tools/benchmark-profile-report.php [test-count]
 * The default is 100,000 tests. Compare identical counts on an idle machine.
 */

require \dirname(__DIR__) . '/vendor/autoload.php';

use Greenlight\Cli\Application;
use Greenlight\Event\RunFinished;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Event\TestStarted;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

if (($argv[1] ?? null) === '--measure') {
    $input = $argv[2] ?? throw new \InvalidArgumentException('Supply the input path.');
    $output = \fopen('php://temp', 'w+');

    if ($output === false) {
        throw new \RuntimeException('Cannot open the report output.');
    }

    $start = \hrtime(true);
    $exit = Application::forStreams($output)->run(['profile:report', '--input=' . $input, '--no-ansi'], \dirname(__DIR__));
    $elapsed = (\hrtime(true) - $start) / 1_000_000_000;
    \rewind($output);
    $report = (string) \stream_get_contents($output);
    \fclose($output);
    echo \json_encode([
        'inputBytes' => \filesize($input),
        'peakBytes' => \memory_get_peak_usage(true),
        'seconds' => $elapsed,
        'reportHash' => \hash('sha256', $report),
        'exitCode' => $exit,
    ], \JSON_THROW_ON_ERROR), "\n";
    exit($exit);
}

$count = \filter_var($argv[1] ?? '100000', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!\is_int($count)) {
    throw new \InvalidArgumentException('Supply a positive test count.');
}

$path = \tempnam(\sys_get_temp_dir(), 'greenlight-profile-benchmark-');

if ($path === false) {
    throw new \RuntimeException('Cannot create the benchmark input.');
}

try {
    $stream = \fopen($path, 'wb');

    if ($stream === false) {
        throw new \RuntimeException('Cannot open the benchmark input.');
    }

    try {
        \fwrite($stream, EventCodec::encodeJsonLine(new RunStarted('benchmark', $count, 1, 1.0)));
        \fwrite($stream, EventCodec::encodeJsonLine(new TestClassStarted('Benchmark\\SuiteTest', 1.0, 'worker-1')));

        for ($index = 0; $index < $count; ++$index) {
            $id = new TestId('Benchmark\\SuiteTest', 'probe', (string) $index);
            \fwrite($stream, EventCodec::encodeJsonLine(new TestStarted($id, 1.0)));
            \fwrite($stream, EventCodec::encodeJsonLine(new TestFinished(new TestResult($id, Outcome::Passed, 0.001, 1), 1.001)));
        }

        \fwrite($stream, EventCodec::encodeJsonLine(new TestClassFinished('Benchmark\\SuiteTest', 2.0, 'worker-1')));
        \fwrite($stream, EventCodec::encodeJsonLine(new RunFinished('benchmark', new ResultSummary(passed: $count), 1.0, 2.0)));
    } finally {
        \fclose($stream);
    }

    $process = \proc_open([\PHP_BINARY, '-d', 'memory_limit=-1', __FILE__, '--measure', $path], [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR], $pipes);

    if (!\is_resource($process)) {
        throw new \RuntimeException('Cannot start the profile measurement.');
    }

    $exit = \proc_close($process);
} finally {
    \unlink($path);
}

exit($exit);
