<?php

declare(strict_types=1);

namespace Greenlight\Result;

/**
 * Applies CI rules that affect the run without changing test outcomes.
 *
 * @internal
 */
final readonly class RunPolicy
{
    public function __construct(
        public bool $failOnSkipped = false,
        public bool $failOnRetriedPass = false,
    ) {}

    /** @param non-negative-int $retriedPasses */
    public function accepts(ResultSummary $summary, int $retriedPasses = 0): bool
    {
        return $summary->isSuccessful()
            && (!$this->failOnSkipped || $summary->skipped === 0)
            && (!$this->failOnRetriedPass || $retriedPasses === 0);
    }
}
