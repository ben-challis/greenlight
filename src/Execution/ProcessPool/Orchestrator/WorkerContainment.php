<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Event\EventSink;
use Greenlight\Event\TestFinished;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\Worker\WorkerError;
use Greenlight\Internal\Text\Utf8;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\TestId;

/**
 * Contains worker crashes and test timeouts.
 *
 * containCrash() and containTimeout() stop the worker, record a synthetic
 * result for its active test, and queue the rest of its assignment again.
 * The worker stops before the result records. Thus, attachment recovery sees
 * all of the evidence that the worker wrote.
 *
 * An exit without an active test can repeat for each replacement worker.
 * Thus, consecutive exits of this type stop the run at a small limit.
 * recordProgress() resets this count.
 *
 * @internal
 */
final class WorkerContainment
{
    private const int DIAGNOSTIC_BYTES = 2_048;
    // Workers that exit before they finish a test usually fail in the same
    // way again, for example after an exit() in a bootstrap file.
    private const int UNATTRIBUTED_DEATH_LIMIT = 3;

    /** @var non-negative-int consecutive worker exits without a finished or attributed test */
    private int $unattributedDeaths = 0;

    /**
     * @param \Closure(WorkerState, bool): list<TestId> $retire stops a worker and returns the tests of its assignment that did not finish and were not active
     * @param \Closure(): bool $draining tells if the run stops new work
     * @param \Closure(): void $resultRecorded applies the failure limit after a synthetic result
     */
    public function __construct(
        private readonly WorkerTransport $transport,
        private readonly OrchestratorConfiguration $configuration,
        private readonly RunLedger $ledger,
        private readonly ResourceScheduler $scheduler,
        private readonly \Closure $retire,
        private readonly \Closure $draining,
        private readonly \Closure $resultRecorded,
    ) {}

    /**
     * Records a finished test. The next unattributed exit starts a new count.
     */
    public function recordProgress(): void
    {
        $this->unattributedDeaths = 0;
    }

    /**
     * @throws AttachmentError
     */
    public function containTimeout(WorkerState $handle, EventSink $sink, float $budget): void
    {
        $inFlight = $handle->inFlight;
        $attempts = $handle->inFlightAttempt;
        $duration = \max(0.0, $this->transport->now() - $handle->inFlightSince);
        $remainder = ($this->retire)($handle, true);

        if ($inFlight instanceof TestId) {
            $this->unattributedDeaths = 0;
            $message = \sprintf(
                'The test exceeded its %.3f-second time limit. Greenlight stopped worker "%s" after %.3f seconds.',
                $budget,
                $handle->workerId,
                $duration,
            );
            $diagnostics = \trim($this->transport->diagnostics($handle->workerId));

            if ($diagnostics !== '') {
                $message .= "\nWorker output:\n" . Utf8::tailBytes($diagnostics, self::DIAGNOSTIC_BYTES);
            }

            $this->recordSyntheticResult($sink, new TestResult(
                $inFlight,
                Outcome::Failed,
                $duration,
                0,
                failures: [new FailureDetail($message)],
            ), $attempts);
        }

        $this->enqueueRemainder($remainder);
    }

    /**
     * Fails the active test of a worker that exited, and queues the rest of
     * its assignment again.
     *
     * @throws AttachmentError
     * @throws ProtocolError
     */
    public function containCrash(WorkerState $handle, EventSink $sink, string $reason): void
    {
        $inFlight = $handle->inFlight;
        $attempts = $handle->inFlightAttempt;
        $reportLost = !$inFlight instanceof TestId
            && $handle->assigned instanceof ExecutionPlan
            && $handle->unfinished() === [];
        $remainder = ($this->retire)($handle, false);
        $diagnostics = \trim($this->transport->diagnostics($handle->workerId));

        if ($inFlight instanceof TestId) {
            $this->unattributedDeaths = 0;
            $this->recordSyntheticResult($sink, new TestResult(
                $inFlight,
                Outcome::Errored,
                0.0,
                0,
                error: ThrowableDetail::fromThrowable(WorkerError::crashedDuringTest(
                    $handle->workerId,
                    $reason,
                    Utf8::tailBytes($diagnostics, self::DIAGNOSTIC_BYTES),
                )),
            ), $attempts);
        } elseif ($reportLost) {
            $this->recordLostReport($handle, $diagnostics);
        } elseif (!($this->draining)()) {
            ++$this->unattributedDeaths;

            if ($this->unattributedDeaths >= self::UNATTRIBUTED_DEATH_LIMIT) {
                throw ProtocolError::workerReplacementLoop(
                    $handle->workerId,
                    $this->unattributedDeaths,
                    $reason,
                    Utf8::tailBytes($diagnostics, self::DIAGNOSTIC_BYTES),
                );
            }
        }

        $this->enqueueRemainder($remainder);
    }

    /**
     * Handles a worker that exited after its last test and before Done.
     *
     * The test results are complete. Coverage and leak data are lost. Thus,
     * the run fails only when it collects this data.
     *
     * @throws ProtocolError
     */
    private function recordLostReport(WorkerState $handle, string $diagnostics): void
    {
        $diagnostics = Utf8::tailBytes($diagnostics, self::DIAGNOSTIC_BYTES);

        if ($this->configuration->coverageSettings instanceof CoverageSettings || $this->configuration->detectLeaks) {
            throw ProtocolError::workerReportLost($handle->workerId, $diagnostics);
        }

        $warning = \sprintf(
            'Worker "%s" exited after its last test and before it sent its assignment report. '
            . 'All test results of the assignment are complete.',
            $handle->workerId,
        );

        if ($diagnostics !== '') {
            $warning .= "\nWorker output:\n" . $diagnostics;
        }

        $this->ledger->warn($warning);
    }

    /**
     * @param non-negative-int $attempts
     * @throws AttachmentError
     */
    private function recordSyntheticResult(EventSink $sink, TestResult $result, int $attempts): void
    {
        $result = $result->withAttempts(\max($result->attempts, $attempts));

        if ($this->configuration->artifactStore instanceof ArtifactStore) {
            $result = $this->configuration->artifactStore->recover($result);
        }

        $this->ledger->recordResult($result->id, $result->outcome);
        $sink->emit(new TestFinished($result, \microtime(true)));
        ($this->resultRecorded)();
    }

    /**
     * @param list<TestId> $ids
     */
    private function enqueueRemainder(array $ids): void
    {
        if ($ids === [] || ($this->draining)()) {
            return;
        }

        $byClass = [];

        foreach ($ids as $id) {
            $entry = $this->ledger->entry($id);

            if (!$entry instanceof PlanEntry) {
                continue;
            }

            if ($entry->definition->scheduling->isolated) {
                $this->scheduler->requeue(new SchedulingUnit(new ExecutionPlan([$entry]), true));

                continue;
            }

            $byClass[$entry->id->class][] = $entry;
        }

        foreach ($byClass as $entries) {
            $this->scheduler->requeue(new SchedulingUnit(new ExecutionPlan($entries), false));
        }
    }
}
