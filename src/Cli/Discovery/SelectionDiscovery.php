<?php

declare(strict_types=1);

namespace Greenlight\Cli\Discovery;

use Greenlight\Cli\Configuration\LoadedConfiguration;
use Greenlight\Config\StorageLayout;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanShard;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Test\TestSelection;

/**
 * Discovers one selected plan and diagnoses unmatched excluded paths.
 *
 * @internal
 */
final readonly class SelectionDiscovery
{
    public function __construct(private LoadedConfiguration $configuration, private string $workingDirectory) {}

    /**
     * @throws DiscoveryError
     */
    public function plan(?TestSelection $selection = null): ExecutionPlan
    {
        $resolved = $this->configuration->resolved;
        $selection ??= $resolved->selection;
        $storage = StorageLayout::resolve(
            $resolved->storage,
            $this->workingDirectory,
            $resolved->suiteSelection->stateIdentity(),
        );
        $plan = new TestDiscoverer()->discover(
            $this->configuration->directories,
            $selection,
            $resolved->order->seed,
            DiscoveryCache::forDirectories($this->configuration->directories, $storage->cacheDirectory),
        );

        return $selection->shard === null
            ? $plan
            : PlanShard::select($plan, \max(1, $selection->shard[0]), \max(1, $selection->shard[1]));
    }

    /** @return list<non-empty-string> */
    public function unmatchedExcludePathWarnings(): array
    {
        $prefixes = $this->configuration->resolved->selection->exclude->paths;
        if ($prefixes === []) {
            return [];
        }

        try {
            $files = new TestDiscoverer()->testFiles($this->configuration->directories);
        } catch (DiscoveryError) {
            return [];
        }

        $warnings = [];
        foreach ($prefixes as $prefix) {
            if (!\array_any($files, static fn(string $file): bool => \str_starts_with($file, $prefix))) {
                $warnings[] = \sprintf('Warning: --exclude-path "%s" did not match a discovered test file.', $prefix);
            }
        }

        return $warnings;
    }
}
