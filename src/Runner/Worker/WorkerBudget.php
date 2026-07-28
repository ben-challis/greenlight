<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

/** @internal */
final readonly class WorkerBudget
{
    /** @var positive-int|null */
    public ?int $maxTests;

    /** @var positive-int|null */
    public ?int $maxMemoryBytes;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        ?int $maxTests = null,
        ?int $maxMemoryBytes = null,
    ) {
        if ($maxTests !== null && $maxTests < 1) {
            throw new \InvalidArgumentException('The worker test budget must be at least 1.');
        }

        if ($maxMemoryBytes !== null && $maxMemoryBytes < 1) {
            throw new \InvalidArgumentException('The worker memory budget must be at least 1 byte.');
        }

        $this->maxTests = $maxTests;
        $this->maxMemoryBytes = $maxMemoryBytes;
    }

    public function exhaustedByCount(int $executed): bool
    {
        return $this->maxTests !== null && $executed >= $this->maxTests;
    }

    public function exhaustedByMemory(): bool
    {
        return $this->maxMemoryBytes !== null && \memory_get_usage(true) >= $this->maxMemoryBytes;
    }
}
