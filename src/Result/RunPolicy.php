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
    public function __construct(public bool $failOnSkipped = false) {}

    public function accepts(ResultSummary $summary): bool
    {
        return $summary->isSuccessful() && (!$this->failOnSkipped || $summary->skipped === 0);
    }
}
