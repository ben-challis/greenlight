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
     * @param array<non-empty-string, positive-int> $machineResourceLimits
     * @param non-empty-string|null $resourceCoordinationNamespace
     */
    public function __construct(
        public WorkerCount $count,
        public array $resourceLimits = [],
        public array $machineResourceLimits = [],
        public ?string $resourceCoordinationNamespace = null,
    ) {}
}
