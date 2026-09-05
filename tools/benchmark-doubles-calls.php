<?php

declare(strict_types=1);

namespace Greenlight\Tools;

/**
 * Measures repeated double calls and separate doubles that receive one call.
 * Run: php tools/benchmark-doubles-calls.php [iterations]
 * Each workload uses seven samples. Proxy generation occurs before timing.
 * Compare equal counts with the same PHP settings on an idle machine.
 */

require \dirname(__DIR__) . '/vendor/autoload.php';

use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;

$iterations = \filter_var($argv[1] ?? '10000', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!\is_int($iterations)) {
    throw new \InvalidArgumentException('Supply a positive iteration count.');
}

$warmup = new Doubles();
$warmup->spy(CallBenchmarkTarget::class)->record(1, 'two');
$warmup->dispose();
unset($warmup);
$rows = [];

foreach (['mock', 'spy', 'one-call-mocks'] as $workload) {
    $samples = [];

    for ($sample = 0; $sample < 7; ++$sample) {
        $doubles = new Doubles();
        $targets = [];
        $targetCount = $workload === 'one-call-mocks' ? $iterations : 1;
        $expectedCalls = $workload === 'one-call-mocks' ? 1 : $iterations;

        for ($target = 0; $target < $targetCount; ++$target) {
            $targets[] = $workload === 'spy'
                ? $doubles->spy(CallBenchmarkTarget::class)
                : $doubles->mock(CallBenchmarkTarget::class, static function (MockPlan $plan) use ($expectedCalls): void {
                    $plan->expects('value')->with(1, 'two')->times($expectedCalls)->andReturns(3);
                });
        }

        $memoryBefore = \memory_get_usage();
        $cpuStartedAt = callCpuMilliseconds();
        $startedAt = \hrtime(true);

        for ($call = 0; $call < $iterations; ++$call) {
            $double = $targets[$workload === 'one-call-mocks' ? $call : 0];

            if ($workload === 'spy') {
                $double->record(1, 'two');
            } elseif ($double->value(1, 'two') !== 3) {
                throw new \LogicException('The double returned an incorrect value.');
            }
        }

        $samples[] = [
            'milliseconds' => (\hrtime(true) - $startedAt) / 1_000_000,
            'cpuMilliseconds' => callCpuMilliseconds() - $cpuStartedAt,
            'retainedBytes' => \memory_get_usage() - $memoryBefore,
        ];

        foreach ($targets as $double) {
            if (\count($doubles->callsTo($double, $workload === 'spy' ? 'record' : 'value')) !== $expectedCalls) {
                throw new \LogicException('The double recorded an incorrect call count.');
            }
        }

        $doubles->dispose();
        unset($double, $targets, $doubles);
        \gc_collect_cycles();
    }

    $rows[] = ['workload' => $workload, 'calls' => $iterations, 'samples' => $samples];
}

echo \json_encode(['php' => \PHP_VERSION, 'rows' => $rows], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT), "\n";

function callCpuMilliseconds(): float
{
    $usage = \getrusage();

    if ($usage === false) {
        throw new \RuntimeException('Cannot read process CPU usage.');
    }

    $userSeconds = $usage['ru_utime.tv_sec'] ?? null;
    $systemSeconds = $usage['ru_stime.tv_sec'] ?? null;
    $userMicroseconds = $usage['ru_utime.tv_usec'] ?? null;
    $systemMicroseconds = $usage['ru_stime.tv_usec'] ?? null;

    if (!\is_int($userSeconds) || !\is_int($systemSeconds) || !\is_int($userMicroseconds) || !\is_int($systemMicroseconds)) {
        throw new \RuntimeException('Process CPU usage does not contain integer time values.');
    }

    return ($userSeconds + $systemSeconds) * 1000 + ($userMicroseconds + $systemMicroseconds) / 1000;
}

interface CallBenchmarkTarget
{
    public function value(int $first, string $second): int;

    public function record(int $first, string $second): void;
}
