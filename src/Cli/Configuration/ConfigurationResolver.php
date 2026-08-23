<?php

declare(strict_types=1);

namespace Greenlight\Cli\Configuration;

use Greenlight\Config\Configuration;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\ExecutionConfiguration;
use Greenlight\Config\ResolvedConfiguration;
use Greenlight\Config\WorkerConfiguration;
use Greenlight\Result\ResultPolicy;

/**
 * Applies settings in this order:
 *
 * 1. Built-in defaults
 * 2. The configuration file
 * 3. Command-line flags
 *
 * The input Configuration already combines the first two sources. This class
 * resolves each command-line value and selects one seed for the command.
 *
 * @internal
 */
final class ConfigurationResolver
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function resolve(Configuration $configuration, CliOverrides $overrides): ResolvedConfiguration
    {
        $executionOverrides = $overrides->execution;
        $artifacts = $configuration->execution->artifacts;

        if ($executionOverrides->artifactsDirectory !== null) {
            $artifacts = $artifacts->withDirectory($executionOverrides->artifactsDirectory);
        }

        $coverage = $configuration->coverage;
        $coverageOverrides = $overrides->coverage;

        if ($coverageOverrides->enablesCoverage()) {
            $coverage ??= new CoverageConfiguration([], null, []);
            $coverage = new CoverageConfiguration(
                $coverage->includePaths,
                $coverage->driver,
                $coverage->exports,
                $coverageOverrides->minimumPercentage ?? $coverage->minimumPercentage,
                $coverageOverrides->maximumUncoveredLines ?? $coverage->maximumUncoveredLines,
                $coverage->requireDriver || $coverageOverrides->requireDriver,
            );
        }

        return new ResolvedConfiguration(
            discovery: $configuration->discovery,
            workers: new WorkerConfiguration(
                count: $executionOverrides->workers ?? $configuration->workers->count,
                resourceLimits: \array_replace($configuration->workers->resourceLimits, $executionOverrides->resourceLimits),
            ),
            execution: new ExecutionConfiguration(
                plugins: $configuration->execution->plugins,
                policy: new ResultPolicy(
                    $configuration->execution->policy->failOnDeprecation || $executionOverrides->policy->failOnDeprecation,
                    $configuration->execution->policy->failOnNotice || $executionOverrides->policy->failOnNotice,
                    $configuration->execution->policy->ignoreDeprecations,
                    $configuration->execution->policy->failOnRisky || $executionOverrides->policy->failOnRisky,
                ),
                stopAfterFailures: $executionOverrides->stopAfterFailures ?? $configuration->execution->stopAfterFailures,
                artifacts: $artifacts,
            ),
            order: $configuration->order->resolve($overrides->seed),
            selection: $overrides->selection,
            coverage: $coverage,
            watch: $configuration->watch,
            storage: $configuration->storage,
        );
    }
}
