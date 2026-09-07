<?php

declare(strict_types=1);

namespace Greenlight\Tools;

/**
 * Measures exact test ID selection for a failed-test rerun.
 * Run: php tools/benchmark-test-selection.php [test-count] [failed-count]
 * Each workload uses seven samples and includes selection construction.
 * Compare equal counts with the same PHP settings on an idle machine.
 */

require \dirname(__DIR__) . '/vendor/autoload.php';

use Greenlight\Test\TestInclusions;
use Greenlight\Test\TestSelection;

$testCount = \filter_var($argv[1] ?? '20000', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$failedCount = \filter_var($argv[2] ?? '10000', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!\is_int($testCount) || !\is_int($failedCount) || $failedCount > $testCount) {
    throw new \InvalidArgumentException('Supply positive test and failed counts. The failed count must not exceed the test count.');
}

$ids = [];

for ($test = 0; $test < $testCount; ++$test) {
    $ids[] = 'App\\ExampleTest::case' . $test;
}

$failed = \array_slice($ids, 0, $failedCount);
$samples = [];

for ($sample = 0; $sample < 7; ++$sample) {
    $memoryBefore = \memory_get_usage();
    $startedAt = \hrtime(true);
    $selection = new TestSelection(include: new TestInclusions(exactIds: $failed));
    $selected = 0;

    foreach ($ids as $id) {
        if ($selection->acceptsId($id)) {
            ++$selected;
        }
    }

    $samples[] = [
        'milliseconds' => (\hrtime(true) - $startedAt) / 1_000_000,
        'retainedBytes' => \memory_get_usage() - $memoryBefore,
    ];

    if ($selected !== $failedCount) {
        throw new \LogicException('The selection accepted an incorrect test count.');
    }

    unset($selection);
}

echo \json_encode([
    'php' => \PHP_VERSION,
    'testCount' => $testCount,
    'failedCount' => $failedCount,
    'samples' => $samples,
], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT), "\n";
