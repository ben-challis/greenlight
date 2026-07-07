<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Core\Result\ResultSummary;
use Greenlight\Coverage\CoverageMap;

/**
 * @internal
 */
final readonly class RunResult
{
    /**
     * @param non-negative-int $plannedTests
     */
    public function __construct(
        public ResultSummary $summary,
        public int $plannedTests,
        public float $durationSeconds,
        public ?int $seed,
        public ?CoverageMap $coverage = null,
    ) {}
}
