<?php

declare(strict_types=1);

namespace Greenlight\Cli\Configuration;

use Greenlight\Cli\Input\CliError;
use Greenlight\Config\Configuration;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\ExecutionConfiguration;
use Greenlight\Config\ResolvedConfiguration;
use Greenlight\Config\WorkerConfiguration;
use Greenlight\Result\ResultPolicy;
use Greenlight\Result\RunPolicy;

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

    /** @throws CliError */
    public static function resolve(Configuration $configuration, CliOverrides $overrides): ResolvedConfiguration
    {
        $executionOverrides = $overrides->execution;
        $artifacts = $configuration->execution->artifacts;

        if ($executionOverrides->artifactsDirectory !== null) {
            $artifacts = $artifacts->withDirectory($executionOverrides->artifactsDirectory);
        }

        $coverageOverrides = $overrides->coverage;
        $coverage = $configuration->coverage;
        $configuredIncludes = $coverage instanceof CoverageConfiguration ? $coverage->includePaths : [];
        $configuredDriver = $coverage instanceof CoverageConfiguration ? $coverage->driver : null;
        $configuredExports = $coverage instanceof CoverageConfiguration ? $coverage->exports : [];
        $configuredMinimum = $coverage instanceof CoverageConfiguration ? $coverage->minimumPercentage : null;
        $configuredMaximum = $coverage instanceof CoverageConfiguration ? $coverage->maximumUncoveredLines : null;
        $configuredRequireDriver = $coverage instanceof CoverageConfiguration && $coverage->requireDriver;
        $configuredPerTestTarget = $coverage instanceof CoverageConfiguration ? $coverage->perTestTarget : null;
        $configuredBranchCoverage = $coverage instanceof CoverageConfiguration && $coverage->branchCoverage;
        $configuredMinimumBranch = $coverage instanceof CoverageConfiguration ? $coverage->minimumBranchPercentage : null;
        $configuredMaximumBranches = $coverage instanceof CoverageConfiguration ? $coverage->maximumUncoveredBranches : null;

        if ($coverageOverrides->disabled) {
            $coverage = null;
        } elseif ($coverage instanceof CoverageConfiguration
            || $coverageOverrides->enablesCoverage()
        ) {
            $coverage = new CoverageConfiguration(
                [
                    ...$configuredIncludes,
                    ...$coverageOverrides->includePaths,
                ],
                $configuredDriver,
                $configuredExports,
                $coverageOverrides->minimumPercentage ?? $configuredMinimum,
                $coverageOverrides->maximumUncoveredLines ?? $configuredMaximum,
                $configuredRequireDriver || $coverageOverrides->requireDriver,
                $coverageOverrides->perTestTarget ?? $configuredPerTestTarget,
                $configuredBranchCoverage || $coverageOverrides->branchCoverage,
                $coverageOverrides->minimumBranchPercentage ?? $configuredMinimumBranch,
                $coverageOverrides->maximumUncoveredBranches ?? $configuredMaximumBranches,
            );
        }

        return new ResolvedConfiguration(
            discovery: $configuration->discovery,
            suiteSelection: SuiteSelectionResolver::resolve(
                $configuration->discovery,
                $overrides->suiteNames,
                $overrides->suiteTags,
            ),
            workers: new WorkerConfiguration(
                count: $executionOverrides->workers ?? $configuration->workers->count,
                resourceLimits: \array_replace($configuration->workers->resourceLimits, $executionOverrides->resourceLimits),
            ),
            execution: new ExecutionConfiguration(
                plugins: $configuration->execution->plugins,
                policy: new ResultPolicy(
                    $configuration->execution->policy->failOnDeprecation || $executionOverrides->policy->failOnDeprecation,
                    $configuration->execution->policy->failOnNotice || $executionOverrides->policy->failOnNotice,
                    $configuration->execution->policy->failOnWarning || $executionOverrides->policy->failOnWarning,
                    $configuration->execution->policy->ignoreDeprecations,
                    $configuration->execution->policy->failOnRisky || $executionOverrides->policy->failOnRisky,
                ),
                runPolicy: new RunPolicy(
                    $configuration->execution->runPolicy->failOnSkipped || $executionOverrides->runPolicy->failOnSkipped,
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
