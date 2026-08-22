<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Defines worker-pool size, replacement limits, and shared resource limits.
 *
 * @internal
 */
final readonly class WorkerConfiguration
{
    /**
     * @param positive-int|null $recycleAfterTests A null value disables test-count worker replacement.
     * @param positive-int $recycleAboveMemoryBytes
     * @param array<non-empty-string, positive-int> $resourceLimits
     */
    public function __construct(
        public WorkerCount $count,
        public ?int $recycleAfterTests,
        public int $recycleAboveMemoryBytes,
        public array $resourceLimits = [],
    ) {}
}
