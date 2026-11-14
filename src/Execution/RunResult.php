<?php

declare(strict_types=1);

namespace Greenlight\Execution;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Result\ResultSummary;
use Greenlight\Test\TestId;

/**
 * @internal
 */
final readonly class RunResult
{
    /**
     * @param non-negative-int $plannedTests
     * @param list<TestId> $leaks
     * @param bool $emptyShard True when the selected shard has no tests but the unsharded plan has tests.
     */
    public function __construct(
        public ResultSummary $summary,
        public int $plannedTests,
        public float $durationSeconds,
        public ?int $seed,
        public ?CoverageMap $coverage = null,
        public array $leaks = [],
        public ArtifactMaintenanceReport $artifactMaintenance = new ArtifactMaintenanceReport(),
        public bool $emptyShard = false,
    ) {}
}
