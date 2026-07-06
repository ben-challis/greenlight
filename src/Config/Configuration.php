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
     */
    public function __construct(
        public array $paths,
        public array $suites,
        public WorkerCount $workers,
        public int $recycleAfterTests,
        public int $recycleAboveMemoryBytes,
        public ?CoverageConfiguration $coverage,
        public array $plugins,
        public ?int $stopAfterFailures,
        public bool $randomizeOrder,
        public ?int $randomSeed,
        public array $groups = [],
    ) {}
}
