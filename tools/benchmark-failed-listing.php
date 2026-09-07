<?php

declare(strict_types=1);

namespace Greenlight\Tools;

/**
 * Measures test-list preparation with many previously failed classes.
 * Run: php tools/benchmark-failed-listing.php [class-count]
 * An empty test directory isolates state load and class priority preparation.
 * Compare the seven samples with identical counts and PHP settings.
 */

require \dirname(__DIR__) . '/vendor/autoload.php';

use Greenlight\Cli\Configuration\CliOverrides;
use Greenlight\Cli\Configuration\ConfigurationResolver;
use Greenlight\Cli\Configuration\LoadedConfiguration;
use Greenlight\Cli\Discovery\SelectionPlan;
use Greenlight\Cli\State\RunState;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\StorageBuilder;
use Greenlight\Config\StorageLayout;
use Greenlight\Sandbox\TemporaryDirectory;

$count = \filter_var($argv[1] ?? '20000', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!\is_int($count)) {
    throw new \InvalidArgumentException('Supply a positive class count.');
}

$temporary = new TemporaryDirectory();

try {
    $directory = $temporary->subdirectory('tests');
    $workingDirectory = $temporary->path();
    $configuration = GreenlightConfig::create()
        ->paths([$directory])
        ->storage(static fn(StorageBuilder $storage) => $storage->rootDirectory('state'))
        ->build();
    $overrides = new CliOverrides();
    $resolved = ConfigurationResolver::resolve($configuration, $overrides);
    $loaded = new LoadedConfiguration($resolved, $workingDirectory . '/greenlight.php', $overrides, [$directory]);
    $layout = StorageLayout::resolve($resolved->storage, $workingDirectory);
    $failures = [];

    for ($class = 0; $class < $count; ++$class) {
        $failures[] = 'App\\Example' . $class . 'Test::first';
        $failures[] = 'App\\Example' . $class . 'Test::second';
    }

    if (!RunState::forFile($layout->runStateFile)->record($failures)) {
        throw new \RuntimeException('Cannot write the benchmark run state.');
    }

    $samples = [];

    for ($sample = 0; $sample < 7; ++$sample) {
        \memory_reset_peak_usage();
        $memoryBefore = \memory_get_usage();
        $start = \hrtime(true);
        $plan = SelectionPlan::resolve($loaded, $workingDirectory, false);
        $samples[] = [
            'milliseconds' => (\hrtime(true) - $start) / 1_000_000,
            'extraPeakBytes' => \memory_get_peak_usage() - $memoryBefore,
        ];

        if ($plan->count() !== 0) {
            throw new \LogicException('An empty test directory must produce an empty plan.');
        }
    }

    echo \json_encode([
        'php' => \PHP_VERSION,
        'classCount' => $count,
        'failedTestCount' => \count($failures),
        'samples' => $samples,
    ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT), "\n";
} finally {
    $temporary->dispose();
}
