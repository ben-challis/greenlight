<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Defines the configured sources for test discovery.
 *
 * @internal
 */
final readonly class DiscoveryConfiguration
{
    /**
     * @param non-empty-list<non-empty-string> $paths
     * @param list<SuiteConfiguration> $suites
     */
    public function __construct(
        public array $paths,
        public array $suites = [],
    ) {}
}
