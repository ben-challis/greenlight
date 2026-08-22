<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Defines worker-pool size and shared resource limits.
 *
 * @internal
 */
final readonly class WorkerConfiguration
{
    /**
     * @param array<non-empty-string, positive-int> $resourceLimits
     */
    public function __construct(
        public WorkerCount $count,
        public array $resourceLimits = [],
    ) {}
}
