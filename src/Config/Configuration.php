<?php

declare(strict_types=1);

namespace Greenlight\Config;

use Greenlight\Core\Result\ResultPolicy;

/**
 * The fully resolved, immutable configuration for a run.
 *
 * GreenlightConfig::build() produces it. After command-line overrides are
 * applied, it is consumed by the runner and exposed to plugins.
 *
 * @internal
 */
final readonly class Configuration
{
    /**
     * @param non-empty-list<non-empty-string> $paths
     * @param list<SuiteConfiguration> $suites
     * @param positive-int|null $recycleAfterTests null means workers are never recycled by test count
     * @param positive-int $recycleAboveMemoryBytes
     * @param list<object> $plugins
     * @param positive-int|null $stopAfterFailures null means run everything regardless of failures
     * @param list<non-empty-string> $groups empty means no group filter
     * @param list<non-empty-string> $filters test id patterns from --filter;
     *   empty means no id filter
     * @param list<non-empty-string>|null $onlyTests exact test ids to run
     *   (the --failed selection); null means no restriction
     * @param array{int, int}|null $shard 1-based shard index and total shard
     *   count from --shard; null means the whole plan
     * @param list<non-empty-string> $excludeGroups groups to drop from the plan
     * @param list<non-empty-string> $excludeClasses class name patterns to drop
     * @param list<non-empty-string> $excludeMethods method name patterns to drop
     * @param list<non-empty-string> $excludePaths path prefixes to drop
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
        );
    }
}
