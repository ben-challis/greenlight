<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\Configuration;

/**
 * Applies command-line overrides to a built configuration. Precedence is
 * fixed: built-in defaults, then the config file, then command-line flags.
 * The first two are already collapsed into the incoming Configuration, so
 * this only decides flag-by-flag whether the command line wins.
 *
 * @internal
 */
final class ConfigurationResolver
{
    private function __construct() {}

    public static function resolve(Configuration $configuration, CliOverrides $overrides): Configuration
    {
        return new Configuration(
            paths: $configuration->paths,
            suites: $configuration->suites,
            workers: $overrides->workers ?? $configuration->workers,
            recycleAfterTests: $configuration->recycleAfterTests,
            recycleAboveMemoryBytes: $configuration->recycleAboveMemoryBytes,
            coverage: $configuration->coverage,
            watch: $configuration->watch,
            plugins: $configuration->plugins,
            stopAfterFailures: $overrides->stopAfterFailures ?? $configuration->stopAfterFailures,
            randomizeOrder: $overrides->seed !== null || $configuration->randomizeOrder,
            randomSeed: $overrides->seed ?? $configuration->randomSeed,
            groups: $overrides->groups === [] ? $configuration->groups : $overrides->groups,
            filters: $overrides->filters,
        );
    }
}
