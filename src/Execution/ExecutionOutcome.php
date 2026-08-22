<?php

declare(strict_types=1);

namespace Greenlight\Execution;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Event\WorkerTiming;
use Greenlight\Result\ResultSummary;
use Greenlight\Test\TestId;

/**
 * Contains the execution data that the run coordinator publishes and returns.
 *
 * @internal
 */
final readonly class ExecutionOutcome
{
    /**
     * @param list<TestId> $leaks
     * @param list<WorkerTiming> $workerTimings
     */
    public function __construct(
        public ResultSummary $summary,
        public ?CoverageMap $coverage = null,
        public array $leaks = [],
        public array $workerTimings = [],
    ) {}
}
