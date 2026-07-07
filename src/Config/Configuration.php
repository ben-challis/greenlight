<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * The fully resolved, immutable configuration for a run. Produced by
 * GreenlightConfig::build() and, after command-line overrides are applied,
 * consumed by the runner and exposed to plugins.
 *
 * @internal
 */
final readonly class Configuration
{
    /**
     * @param non-empty-list<non-empty-string> $paths
     * @param list<SuiteConfiguration> $suites
     * @param positive-int $recycleAfterTests
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
     */
    public function __construct(
        public array $paths,
        public array $suites,
        public WorkerCount $workers,
        public int $recycleAfterTests,
        public int $recycleAboveMemoryBytes,
        public ?CoverageConfiguration $coverage,
        public WatchConfiguration $watch,
        public array $plugins,
        public ?int $stopAfterFailures,
        public bool $randomizeOrder,
        public ?int $randomSeed,
        public array $groups = [],
        public array $filters = [],
        public ?array $onlyTests = null,
        public ?array $shard = null,
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
            stopAfterFailures: $this->stopAfterFailures,
            randomizeOrder: $this->randomizeOrder,
            randomSeed: $this->randomSeed,
            groups: $this->groups,
            filters: $this->filters,
            onlyTests: $ids,
            shard: $this->shard,
        );
    }
}
