<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\Configuration;
use Greenlight\Core\Result\ResultPolicy;

/**
 * Applies settings in this order:
 *
 * 1. Built-in defaults
 * 2. The configuration file
 * 3. Command-line flags
 *
 * The input Configuration already combines the first two sources. resolve()
 * determines if each command-line flag replaces a configuration value.
 *
 * If random order is active and neither source supplies a seed, resolve()
 * selects one seed.
 *
 * @internal
 */
final class ConfigurationResolver
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function resolve(Configuration $configuration, CliOverrides $overrides): Configuration
    {
        $randomizeOrder = $overrides->seed !== null || $configuration->randomizeOrder;
        $randomSeed = $overrides->seed ?? $configuration->randomSeed;
        $artifacts = $configuration->artifacts;

        if ($overrides->artifactsDirectory !== null) {
            $artifacts = $artifacts->withDirectory($overrides->artifactsDirectory);
        }

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
            onlyTests: $overrides->testIds === [] ? null : $overrides->testIds,
            shard: $overrides->shard,
            excludeGroups: $overrides->excludeGroups,
            excludeClasses: $overrides->excludeClasses,
            excludeMethods: $overrides->excludeMethods,
            excludePaths: $overrides->excludePaths,
            artifacts: $artifacts,
            resourceLimits: \array_replace($configuration->resourceLimits, $overrides->resourceLimits),
            storage: $configuration->storage,
        );
    }
}
