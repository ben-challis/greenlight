<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\Configuration;
use Greenlight\Core\Result\ResultPolicy;

/**
 * Applies command-line overrides to a built configuration.
 *
 * Precedence is fixed: built-in defaults, then the config file, then
 * command-line flags. The first two are already collapsed into the incoming
 * Configuration, so resolve() only decides flag-by-flag whether the command
 * line wins.
 *
 * When randomization is enabled but neither source supplies a seed, resolve()
 * chooses one, once, so every consumer of the resolved Configuration (the run
 * header, discovery, the runners) sees the same value.
 *
 * @internal
 */
final class ConfigurationResolver
{
    private function __construct() {}

    public static function resolve(Configuration $configuration, CliOverrides $overrides): Configuration
    {
        $randomizeOrder = $overrides->seed !== null || $configuration->randomizeOrder;
        $randomSeed = $overrides->seed ?? $configuration->randomSeed;

        if ($randomizeOrder && $randomSeed === null) {
            $randomSeed = \random_int(0, 2 ** 31 - 1);
        }

        return new Configuration(
            paths: $configuration->paths,
            suites: $configuration->suites,
            workers: $overrides->workers ?? $configuration->workers,
            recycleAfterTests: $configuration->recycleAfterTests,
            recycleAboveMemoryBytes: $configuration->recycleAboveMemoryBytes,
            coverage: $configuration->coverage,
            watch: $configuration->watch,
            plugins: $configuration->plugins,
            policy: new ResultPolicy(
                $configuration->policy->failOnDeprecation || $overrides->failOnDeprecation,
                $configuration->policy->failOnNotice || $overrides->failOnNotice,
                $configuration->policy->ignoreDeprecations,
                $configuration->policy->failOnRisky || $overrides->failOnRisky,
            ),
            stopAfterFailures: $overrides->stopAfterFailures ?? $configuration->stopAfterFailures,
            randomizeOrder: $randomizeOrder,
            randomSeed: $randomSeed,
            groups: $overrides->groups === [] ? $configuration->groups : $overrides->groups,
            filters: $overrides->filters,
            shard: $overrides->shard,
            excludeGroups: $overrides->excludeGroups,
            excludeClasses: $overrides->excludeClasses,
            excludeMethods: $overrides->excludeMethods,
            excludePaths: $overrides->excludePaths,
        );
    }
}
