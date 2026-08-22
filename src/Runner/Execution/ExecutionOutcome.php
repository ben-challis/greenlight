<?php

declare(strict_types=1);

namespace Greenlight\Runner\Execution;

use Greenlight\Core\Event\WorkerTiming;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;
use Greenlight\Coverage\CoverageMap;

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
