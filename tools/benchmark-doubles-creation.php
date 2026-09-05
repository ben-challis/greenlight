<?php

declare(strict_types=1);

namespace Greenlight\Tools;

/**
 * Measures repeated public double creation with loaded proxy classes.
 * Run: php tools/benchmark-doubles-creation.php [iterations]
 * Each contract uses seven samples after 100 warm-up iterations.
 * Compare identical counts on an idle machine with the same PHP settings.
 */

require \dirname(__DIR__) . '/vendor/autoload.php';

use Greenlight\Doubles\Doubles;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\Wide;

$iterations = \filter_var($argv[1] ?? '5000', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!\is_int($iterations)) {
    throw new \InvalidArgumentException('Supply a positive iteration count.');
}

$rows = [];

foreach ([Calculator::class, Wide::class] as $type) {
    foreach ([false, true] as $newFactory) {
        $doubles = new Doubles();

        for ($index = 0; $index < 100; ++$index) {
            $double = $doubles->stub($type);
            $doubles->dispose();
            unset($double);
        }

        $wallSamples = [];
        $cpuSamples = [];

        for ($sample = 0; $sample < 7; ++$sample) {
            $start = \hrtime(true);
            $cpuStart = cpuMilliseconds();

            for ($index = 0; $index < $iterations; ++$index) {
                if ($newFactory) {
                    $doubles = new Doubles();
                }

                $double = $doubles->stub($type);
                $doubles->dispose();
                unset($double);
            }

            $cpuSamples[] = cpuMilliseconds() - $cpuStart;
            $wallSamples[] = (\hrtime(true) - $start) / 1_000_000;
        }

        $rows[] = [
            'type' => $type,
            'methodCount' => \count(new \ReflectionClass($type)->getMethods()),
            'newFactory' => $newFactory,
            'iterations' => $iterations,
            'wallMilliseconds' => $wallSamples,
            'cpuMilliseconds' => $cpuSamples,
        ];
    }
}

echo \json_encode(['php' => \PHP_VERSION, 'rows' => $rows], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT), "\n";

function cpuMilliseconds(): float
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
