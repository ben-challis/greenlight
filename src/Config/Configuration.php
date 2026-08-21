<?php

declare(strict_types=1);

namespace Greenlight\Config;

use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Plugin\PluginDefinition;

/** @internal */
final readonly class Configuration
{
    /**
     * @param non-empty-list<non-empty-string> $paths
     * @param list<SuiteConfiguration> $suites
     * @param positive-int|null $recycleAfterTests A null value disables
     *   test-count worker replacement.
     * @param positive-int $recycleAboveMemoryBytes
     * @param list<PluginDefinition> $plugins
     * @param positive-int|null $stopAfterFailures A null value runs all tests
     *   regardless of failures.
     * @param list<non-empty-string> $groups An empty list disables the group
     *   filter.
     * @param list<non-empty-string> $filters Test ID patterns from --filter.
     *   An empty list disables the test ID filter.
     * @param list<non-empty-string>|null $onlyTests Exact test IDs from
     *   --test-id or --failed. A null value removes this restriction.
     * @param array{int, int}|null $shard 1-based shard index and total shard
     *   count from --shard. A null value selects the complete execution plan.
     * @param list<non-empty-string> $excludeGroups Groups to exclude from the
     *   execution plan.
     * @param list<non-empty-string> $excludeClasses Class-name patterns to
     *   exclude.
     * @param list<non-empty-string> $excludeMethods Method-name patterns to
     *   exclude.
     * @param list<non-empty-string> $excludePaths Path prefixes to exclude.
     * @param array<non-empty-string, positive-int> $resourceLimits Configured
     *   local resource limits.
     */
    public function __construct(
        public array $paths,
        public array $suites,
        public WorkerCount $workers,
        public ?int $recycleAfterTests,
        public int $recycleAboveMemoryBytes,
        public ?CoverageConfiguration $coverage,
        public WatchConfiguration $watch,
        public array $plugins,
        public ResultPolicy $policy,
        public ?int $stopAfterFailures,
        public bool $randomizeOrder,
        public ?int $randomSeed,
        public array $groups = [],
        public array $filters = [],
        public ?array $onlyTests = null,
        public ?array $shard = null,
        public array $excludeGroups = [],
        public array $excludeClasses = [],
        public array $excludeMethods = [],
        public array $excludePaths = [],
        public ArtifactConfiguration $artifacts = new ArtifactConfiguration(),
        public array $resourceLimits = [],
    ) {}

    /**
     * @param list<non-empty-string> $ids
     */
    public function withOnlyTests(array $ids): self
    {
        return new self(
            paths: $this->paths,
            suites: $this->suites,
            workers: $this->workers,
            recycleAfterTests: $this->recycleAfterTests,
            recycleAboveMemoryBytes: $this->recycleAboveMemoryBytes,
            coverage: $this->coverage,
            watch: $this->watch,
            plugins: $this->plugins,
            policy: $this->policy,
            stopAfterFailures: $this->stopAfterFailures,
            randomizeOrder: $this->randomizeOrder,
            randomSeed: $this->randomSeed,
            groups: $this->groups,
            filters: $this->filters,
            onlyTests: $ids,
            shard: $this->shard,
            excludeGroups: $this->excludeGroups,
            excludeClasses: $this->excludeClasses,
            excludeMethods: $this->excludeMethods,
            excludePaths: $this->excludePaths,
            artifacts: $this->artifacts,
            resourceLimits: $this->resourceLimits,
        );
    }

    /**
     * @param list<non-empty-string> $paths
     */
    public function withExcludePaths(array $paths): self
    {
        return new self(
            paths: $this->paths,
            suites: $this->suites,
            workers: $this->workers,
            recycleAfterTests: $this->recycleAfterTests,
            recycleAboveMemoryBytes: $this->recycleAboveMemoryBytes,
            coverage: $this->coverage,
            watch: $this->watch,
            plugins: $this->plugins,
            policy: $this->policy,
            stopAfterFailures: $this->stopAfterFailures,
            randomizeOrder: $this->randomizeOrder,
            randomSeed: $this->randomSeed,
            groups: $this->groups,
            filters: $this->filters,
            onlyTests: $this->onlyTests,
            shard: $this->shard,
            excludeGroups: $this->excludeGroups,
            excludeClasses: $this->excludeClasses,
            excludeMethods: $this->excludeMethods,
            excludePaths: $paths,
            artifacts: $this->artifacts,
            resourceLimits: $this->resourceLimits,
        );
    }
}
