<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;

/**
 * What one worker run produced: the tally of executed tests, the entries it
 * never reached, and why it stopped early when it did.
 *
 * @internal
 */
final readonly class WorkerRunOutcome
{
    /**
     * @param list<TestId> $remaining unexecuted entries, in plan order
     * @param list<TestId> $leaks tests whose instances survived their test, leak detection only
     */
    public function __construct(
        public ResultSummary $summary,
        public array $remaining = [],
        public ?RecycleReason $recycleReason = null,
        public bool $drained = false,
        public array $leaks = [],
    ) {}
}
