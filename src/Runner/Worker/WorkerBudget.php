<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

/**
 * Holds the recycling thresholds a worker checks after each test.
 *
 * The worker knows its own memory; recycling is worker-initiated.
 *
 * @internal
 */
final readonly class WorkerBudget
{
    /**
     * @param positive-int|null $maxTests
     * @param positive-int|null $maxMemoryBytes
     */
    public function __construct(
        public ?int $maxTests = null,
        public ?int $maxMemoryBytes = null,
    ) {}

    public function exhaustedByCount(int $executed): bool
    {
        return $this->maxTests !== null && $executed >= $this->maxTests;
    }

    public function exhaustedByMemory(): bool
    {
        return $this->maxMemoryBytes !== null && \memory_get_usage(true) >= $this->maxMemoryBytes;
    }
}
