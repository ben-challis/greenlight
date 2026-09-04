<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Event\Event;
use Greenlight\Event\EventSink;
use Greenlight\Event\TestFinished;
use Greenlight\Event\TestStarted;
use Greenlight\Event\WorkerSpawned;
use Greenlight\Event\WorkerTiming;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Assign;
use Greenlight\Execution\ProcessPool\Protocol\Messages\AttemptStarted;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Bootstrap;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Done;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Drain;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Fatal;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Hello;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Ready;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\Worker\WorkerError;
use Greenlight\Internal\Text\Utf8;
use Greenlight\Internal\Wire\WireCommunicationFailed;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\TestId;

/**
 * Workers request test classes when required. Isolated entries use new
 * processes. A crash fails the active test and puts the remainder of its
 * assignment in the queue again.
 *
 * Bail and graceful shutdown stop new assignments and drain active workers.
 * They also collect each process. Queue order and order in a class are
 * deterministic. Worker placement depends on load.
 *
 * Deadlines apply to workers that do not authenticate. They also apply to
 * authenticated workers that stop progress outside an active test.
 *
 * @internal
 */
final class Orchestrator
{
    private const float HELLO_DEADLINE_SECONDS = 10.0;
    // A worker usually starts in less than one second. This deadline stops the
    // complete run. Thus, it permits slow starts on a loaded computer with
    // debug extensions.
    // These periods usually take milliseconds. They occur between assignment
    // receipt and the first TestStarted or between tests. This deadline stops
    // the complete run, so it permits longer periods.
    private const float TIMEOUT_GRACE_FACTOR = 2.0;
    private const float TIMEOUT_GRACE_FLAT_SECONDS = 2.0;
    private ?ResourceScheduler $scheduler = null;

    /**
     * @var array<string, WorkerState>
     */
    private array $handles = [];

    /**
     * @var array<string, WorkerTiming>
     */
    private array $reapedWorkerTimings = [];

    /**
     * @var array<int, float> accepted connection time by transport connection ID
     */
    private array $awaitingHello = [];

    /**
     * @var array<string, PlanEntry>
     */
    private array $entriesById = [];

    private ResultSummary $summary;

    private ?CoverageMap $coverage = null;

    /**
     * @var list<TestId>
     */
    private array $leaks = [];

    private bool $draining = false;

    private bool $retryWaitingWorkers = false;

    private ?ChannelAllocator $channels = null;

    /** @var positive-int */
    private int $initialWorkerTarget = 1;

    private bool $initialBarrierPassed = false;

    /**
     * @var array<non-empty-string, true> initial workers that have not received their first assignment
     */
    private array $initialAssignmentsPending = [];

    public function __construct(
        private readonly WorkerTransport $transport,
        private readonly OrchestratorConfiguration $configuration = new OrchestratorConfiguration(),
    ) {
        $this->summary = new ResultSummary();
    }

    /**
     * Contains coverage from worker reports.
     *
     * The orchestrator merges reports when they arrive. A null value means
     * that coverage was off or no worker could collect it.
     */
    public function collectedCoverage(): ?CoverageMap
    {
        return $this->coverage;
    }

    /**
     * @return list<TestId>
     */
    public function detectedLeaks(): array
    {
        return $this->leaks;
    }

    /**
     * Returns orchestrator-observed timing data for each spawned worker.
     *
     * @return list<WorkerTiming>
     */
    public function workerTimings(): array
    {
        $timings = $this->reapedWorkerTimings;

        foreach ($this->handles as $handle) {
            $timings[$handle->workerId] = $handle->timing->snapshot($handle->workerId);
        }

        return \array_values($timings);
    }

    private function mergeCoverage(?CoverageMap $coverage): void
    {
        if (!$coverage instanceof CoverageMap) {
            return;
        }

        $this->coverage = $this->coverage instanceof CoverageMap ? $this->coverage->merge($coverage) : $coverage;
    }

    /**
     * @param positive-int $workerCount
     * @param array<string, float> $classSeconds Recorded class durations.
     *
     * @throws ProtocolError
     * @throws AttachmentError
     * @throws WireCommunicationFailed
     * @throws ReportGenerationFailed
     */
    public function run(ExecutionPlan $plan, EventSink $sink, int $workerCount, array $classSeconds = []): ResultSummary
    {
        foreach ($plan->entries as $entry) {
            $this->entriesById[(string) $entry->id] = $entry;
        }

        [$pooled, $isolated] = new Distributor()->units($plan, $classSeconds, $workerCount);
        $this->scheduler = new ResourceScheduler($pooled, $isolated, $this->configuration->resourceLimits);

        if ($this->scheduler->pendingCount() === 0) {
            $this->transport->close();

            return $this->summary;
        }

        $this->initialWorkerTarget = $this->resourceScheduler()->initialWorkerTarget($workerCount);
        $this->initialBarrierPassed = $this->configuration->initialWorkerAssignment === InitialWorkerAssignment::Progressive;

        $spawnBudget = new WorkerSpawnBudget(\count($plan->entries), $workerCount);

        try {
            while (true) {
                if (!$this->draining && $this->configuration->shutdown?->requested() === true) {
                    $this->drainAll();
                }

                $this->spawnUpTo($this->initialWorkerTarget, $sink, $spawnBudget);

                if ($this->finished()) {
                    break;
                }

                $this->tick($sink);
                $this->configuration->ticker?->tick(\microtime(true));
            }
        } finally {
            $this->transport->close();
        }

        return $this->summary;
    }

    /**
     * @param positive-int $workerTarget
     * @throws ProtocolError
     */
    private function spawnUpTo(
        int $workerTarget,
        EventSink $sink,
        WorkerSpawnBudget $spawnBudget,
    ): void {
        // Isolated and reused workers use the same worker pool. The active
        // count below limits live workers to the worker target. Thus, the
        // allocator has a channel for each possible live worker.
        $channels = $this->channels ??= new ChannelAllocator($workerTarget);

        while (!$this->draining
            && $this->pendingUnits() > $this->unassignedActiveCount()
            && \count($this->handles) < $workerTarget
        ) {
            $workerId = $spawnBudget->nextWorkerId();

            $channelNumber = $channels->allocate();
            try {
                $pid = $this->transport->start($workerId, $channelNumber);
            } catch (\Throwable $error) {
                $channels->release($channelNumber);

                throw $error;
            }

            $handle = new WorkerState($workerId, $channelNumber, $this->monotonicTime());
            $this->handles[$workerId] = $handle;

            if (\count($this->handles) <= $this->initialWorkerTarget) {
                $this->initialAssignmentsPending[$workerId] = true;
            }

            $sink->emit(new WorkerSpawned($workerId, $pid, \microtime(true)));
        }
    }

    private function finished(): bool
    {
        if ($this->pendingUnits() > 0 && !$this->draining) {
            return false;
        }
        return $this->handles === [];
    }

    private function pendingUnits(): int
    {
        return $this->resourceScheduler()->pendingCount();
    }

    /**
     * Returns live workers without a first assignment.
     *
     * The orchestrator assigns queued scheduling units to these workers. Thus,
     * it does not start more workers for the same units.
     */
    private function unassignedActiveCount(): int
    {
        $count = 0;

        foreach ($this->handles as $handle) {
            if ($handle->isActive() && $handle->assigned === null) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @throws AttachmentError
     * @throws WireCommunicationFailed
     * @throws ProtocolError
     */
    private function tick(EventSink $sink): void
    {
        $this->processTransportEvents($this->transport->poll(), $sink);
        $this->expireConnections();
        $this->detectCrashes();
        $this->enforceTimeouts($sink);
        $this->assignWaiting($sink);
    }

    /**
     * @param list<WorkerTransportEvent> $events
     * @throws WireCommunicationFailed
     * @throws AttachmentError
     * @throws ProtocolError
     */
    private function processTransportEvents(array $events, EventSink $sink): void
    {
        foreach ($events as $event) {
            if ($event->kind === WorkerTransportEventKind::ConnectionAccepted) {
                \assert($event->connectionId !== null);
                $this->awaitingHello[$event->connectionId] = $this->monotonicTime();

                continue;
            }

            if ($event->kind === WorkerTransportEventKind::ConnectionClosed) {
                \assert($event->connectionId !== null);
                unset($this->awaitingHello[$event->connectionId]);

                continue;
            }

            if ($event->kind === WorkerTransportEventKind::ConnectionMessage) {
                \assert($event->connectionId !== null && $event->message instanceof Message);
                $this->processHello($event->connectionId, $event->message, $sink);

                continue;
            }

            if ($event->kind === WorkerTransportEventKind::WorkerMessage) {
                \assert($event->workerId !== null && $event->message instanceof Message);
                $handle = $this->handles[$event->workerId] ?? null;

                if ($handle instanceof WorkerState && $handle->isActive()) {
                    $handle->lastProgressAt = $this->monotonicTime();
                    $this->processWorkerMessage($handle, $event->message, $sink);
                }

                continue;
            }

            if ($event->kind === WorkerTransportEventKind::WorkerDisconnected) {
                \assert($event->workerId !== null);
                $handle = $this->handles[$event->workerId] ?? null;

                if ($handle instanceof WorkerState && $handle->isActive()) {
                    if ($handle->stopRequested && !$handle->assigned instanceof ExecutionPlan) {
                        $this->finishHandle($handle);

                        continue;
                    }

                    $reason = $handle->connected
                        ? 'the worker process exited unexpectedly'
                        : 'the worker exited before connecting';
                    $this->containCrash($handle, $sink, $reason);
                }

                continue;
            }

            if ($event->kind === WorkerTransportEventKind::WorkerRetired) {
                \assert($event->workerId !== null);
                $this->completeRetirement($event->workerId);
            }
        }
    }

    /** @throws AttachmentError */
    private function processHello(int $connectionId, Message $message, EventSink $sink): void
    {
        unset($this->awaitingHello[$connectionId]);

        if (!$message instanceof Hello || $message->token !== $this->transport->token()) {
            $this->transport->resolveConnection($connectionId, null);

            return;
        }

        $handle = $this->handles[$message->workerId] ?? null;

        if (!$handle instanceof WorkerState || $handle->connected || !$handle->isActive()) {
            $this->transport->resolveConnection($connectionId, null);

            return;
        }

        $this->transport->resolveConnection($connectionId, $handle->workerId);
        $handle->connected = true;
        $handle->lastProgressAt = $this->monotonicTime();
        $handle->timing->hello($handle->lastProgressAt);

        try {
            $this->transport->send($handle->workerId, new Bootstrap(
                $handle->channelNumber,
                $this->configuration->configFile === '' ? null : $this->configuration->configFile,
                $this->configuration->integrationFixtures->forChannel($handle->channelNumber),
                $this->configuration->generatedCodeDirectory,
                $this->configuration->temporaryDirectory,
                $this->configuration->policy,
            ));
        } catch (ProtocolError) {
            $this->containCrash($handle, $sink, 'the worker exited before receiving bootstrap data');
        }
    }

    private function expireConnections(): void
    {
        $now = $this->monotonicTime();

        foreach ($this->awaitingHello as $connectionId => $since) {
            if ($now - $since <= self::HELLO_DEADLINE_SECONDS) {
                continue;
            }

            $this->transport->resolveConnection($connectionId, null);
            unset($this->awaitingHello[$connectionId]);
        }
    }

    /**
     * Gives the next suitable queued scheduling unit to a connected worker.
     *
     * If no suitable work remains, drains the worker. A worker can remain
     * connected without an assignment while a resource lease is unavailable.
     * @throws AttachmentError
     */
    private function assignNext(WorkerState $handle, EventSink $sink): void
    {
        if (!$handle->connected || !$handle->ready) {
            return;
        }

        if (!$handle->isFresh() && $this->initialAssignmentsPending !== []) {
            $handle->timing->wait(WorkerIdleReason::BootstrapBarrier, $this->monotonicTime());

            return;
        }

        $decision = $this->draining
            ? DispatchDecision::drain()
            : $this->resourceScheduler()->dispatch($handle->isFresh());

        if ($decision->kind === DispatchKind::Wait) {
            $handle->timing->wait(WorkerIdleReason::ResourceCapacity, $this->monotonicTime());

            return;
        }

        if ($decision->kind === DispatchKind::Drain) {
            if ($handle->stopRequested) {
                return;
            }

            $at = $this->monotonicTime();
            $handle->timing->wait(WorkerIdleReason::NoQueuedWork, $at);
            $handle->timing->retirementRequested($at);

            try {
                $this->transport->send($handle->workerId, new Drain());
            } catch (ProtocolError) {
                // Crash detection processes a worker that is already gone.
            }

            $handle->requestStop($this->monotonicTime());

            return;
        }

        $lease = $decision->lease;

        if (!$lease instanceof ResourceLease) {
            throw new \LogicException('An assign decision must carry a resource lease.');
        }

        $handle->beginAssignment($lease);

        if (isset($this->initialAssignmentsPending[$handle->workerId])) {
            unset($this->initialAssignmentsPending[$handle->workerId]);
            $this->retryWaitingWorkers = true;
        }

        try {
            $this->transport->send($handle->workerId, new Assign(
                $lease->unit->plan,
                $this->configuration->coverageSettings?->includePaths,
                $this->configuration->coverageSettings?->driver,
                $this->configuration->detectLeaks,
                $this->configuration->policy,
                $this->configuration->artifactStore?->session(),
                $this->configuration->artifactConfiguration,
                $this->remainingFailureAllowance(),
            ));
            $handle->lastProgressAt = $this->monotonicTime();
            $handle->timing->assigned($handle->lastProgressAt);
        } catch (ProtocolError) {
            // The worker stopped before it received the assignment. Crash
            // containment puts the complete scheduling unit in the queue for
            // a replacement worker.
            $this->containCrash($handle, $sink, 'the worker exited before receiving its assignment');
        }
    }

    /**
     * @throws AttachmentError
     * @throws WireCommunicationFailed
     * @throws ProtocolError
     */
    private function processWorkerMessage(WorkerState $handle, Message $message, EventSink $sink): void
    {
        if ($message instanceof EventEnvelope) {
            $this->onEvent($handle, $message->event, $sink);
        } elseif ($message instanceof AttemptStarted) {
            $this->onAttemptStarted($handle, $message);
        } elseif ($message instanceof Ready) {
            if ($handle->ready) {
                throw ProtocolError::malformedFrame(\sprintf(
                    'worker "%s" reported ready more than once',
                    $handle->workerId,
                ));
            }

            $handle->ready = true;
            $readyAt = $this->monotonicTime();
            $handle->timing->ready($readyAt);

            if (!$this->initialBarrierPassed) {
                $handle->timing->wait(WorkerIdleReason::BootstrapBarrier, $readyAt);
                $this->openInitialBarrier($sink);
            } else {
                // Progressive initial workers and replacements can
                // start as soon as their own bootstrap completes.
                $this->assignNext($handle, $sink);
            }
        } elseif ($message instanceof Done) {
            $at = $this->monotonicTime();
            $handle->timing->assignmentFinished($at);
            $this->crossCheck($handle, $message->summary);
            $this->mergeCoverage($message->coverage);
            $this->leaks = [...$this->leaks, ...$message->leaks];
            $isolatedAssignment = $handle->isolatedAssignment;
            $this->releaseAssignment($handle);

            if ($this->draining || $isolatedAssignment) {
                $handle->timing->retirementRequested($this->monotonicTime());
                try {
                    $this->transport->send($handle->workerId, new Drain());
                } catch (ProtocolError) {
                    // The worker is already gone after Done. No drain is necessary.
                }

                $handle->requestStop($this->monotonicTime());

                return;
            }

            $this->assignNext($handle, $sink);

        } elseif ($message instanceof Fatal) {
            throw ProtocolError::workerFatal(
                $handle->workerId,
                $message->detail->message,
                $message->detail->file,
                $message->detail->line,
            );
        }
    }

    /**
     * @throws AttachmentError
     */
    private function openInitialBarrier(EventSink $sink): void
    {
        $ready = 0;

        foreach ($this->handles as $handle) {
            if ($handle->isActive() && $handle->ready) {
                ++$ready;
            }
        }

        if ($ready < $this->initialWorkerTarget) {
            return;
        }

        $this->initialBarrierPassed = true;

        foreach ($this->handles as $handle) {
            if ($handle->isActive() && $handle->ready && $handle->assigned === null) {
                // Every initial worker is fresh, so once pooled work is gone
                // it may take an isolated unit.
                $this->assignNext($handle, $sink);
            }
        }
    }

    /**
     * @throws AttachmentError
     */
    private function onEvent(WorkerState $handle, Event $event, EventSink $sink): void
    {
        if ($event instanceof TestFinished && $this->configuration->artifactStore instanceof ArtifactStore) {
            $event = new TestFinished(
                $this->configuration->artifactStore->publish($event->result),
                $event->occurredAt,
            );
        }

        if ($event instanceof TestStarted) {
            $handle->inFlight = $event->id;
            $handle->inFlightSince = $this->monotonicTime();
            $handle->inFlightAttemptSince = $handle->inFlightSince;
            $handle->inFlightAttempt = 0;
        }

        if ($event instanceof TestFinished) {
            $handle->inFlight = null;
            $handle->inFlightAttempt = 0;
            $handle->finished[(string) $event->result->id] = true;
            // Completed tests do not need plan lookups. The index contains
            // only incomplete tests and becomes smaller during the run.
            unset($this->entriesById[(string) $event->result->id]);
            $handle->tally = $handle->tally->add($event->result->outcome);
            $this->summary = $this->summary->add($event->result->outcome);
        }

        $sink->emit($event);

        if ($event instanceof TestFinished) {
            $this->enforceFailureLimit();
        }
    }

    private function enforceFailureLimit(): void
    {
        if ($this->configuration->stopAfterFailures !== null
            && !$this->draining
            && $this->summary->failed + $this->summary->errored >= $this->configuration->stopAfterFailures
        ) {
            $this->drainAll();
        }
    }

    /**
     * @throws ProtocolError
     */
    private function onAttemptStarted(WorkerState $handle, AttemptStarted $message): void
    {
        $inFlight = $handle->inFlight;
        $expectedAttempt = $handle->inFlightAttempt + 1;

        if (!$inFlight instanceof TestId
            || !$inFlight->equals($message->id)
            || $message->attempt !== $expectedAttempt
        ) {
            throw ProtocolError::unexpectedAttempt(
                $handle->workerId,
                (string) $message->id,
                $message->attempt,
                $inFlight instanceof TestId ? (string) $inFlight : null,
                $expectedAttempt,
            );
        }

        $handle->inFlightAttempt = $message->attempt;
        $handle->inFlightAttemptSince = $this->monotonicTime();
    }

    /**
     * @return positive-int|null
     */
    private function remainingFailureAllowance(): ?int
    {
        if ($this->configuration->stopAfterFailures === null) {
            return null;
        }

        return \max(
            1,
            $this->configuration->stopAfterFailures - $this->summary->failed - $this->summary->errored,
        );
    }

    private function drainAll(): void
    {
        $this->draining = true;
        $this->resourceScheduler()->clearPending();

        foreach ($this->handles as $handle) {
            if ($handle->isActive() && $handle->connected && !$handle->stopRequested) {
                $handle->timing->retirementRequested($this->monotonicTime());
                try {
                    $this->transport->send($handle->workerId, new Drain());
                } catch (ProtocolError) {
                    // Crash detection processes a worker that is already gone.
                }

                $handle->requestStop($this->monotonicTime());
            }
        }
    }

    /**
     * @throws ProtocolError
     */
    private function detectCrashes(): void
    {
        foreach ($this->handles as $handle) {
            if (!$handle->isActive()) {
                continue;
            }

            if (!$handle->connected) {
                // This process is alive but did not connect before the
                // deadline. Crash containment cannot detect an active process.
                // The hello deadline starts only after connection acceptance.
                // On a computer without sufficient resources, a replacement
                // can stop in the same way. Thus, this condition fails the run
                // and does not use the replacement budget.
                if ($this->monotonicTime() - $handle->spawnedAt > $this->configuration->connectDeadlineSeconds) {
                    $diagnostics = $this->transport->diagnostics($handle->workerId);
                    $this->transport->retire($handle->workerId, true);

                    throw ProtocolError::workerNeverConnected(
                        $handle->workerId,
                        $this->configuration->connectDeadlineSeconds,
                        Utf8::tailBytes(\trim($diagnostics), 2_048),
                    );
                }

                continue;
            }
        }
    }

    /**
     * @throws AttachmentError
     * @throws ProtocolError
     */
    private function enforceTimeouts(EventSink $sink): void
    {
        foreach ($this->handles as $handle) {
            if (!$handle->isActive()) {
                continue;
            }

            if ($handle->inFlight === null) {
                if ($handle->assigned === null && $handle->ready && !$handle->stopRequested) {
                    // The scheduler keeps this connected worker idle until a
                    // resource lease is available.
                    continue;
                }

                // No test timeout applies between messages. Thus, a worker can
                // stop after assignment receipt or between tests without a
                // test timeout. A replacement worker can stop in the same way.
                // Fail the run instead of use crash containment.
                if ($handle->connected
                    && $this->monotonicTime() - $handle->lastProgressAt > $this->configuration->progressDeadlineSeconds
                ) {
                    $diagnostics = $this->transport->diagnostics($handle->workerId);
                    $this->transport->retire($handle->workerId, true);

                    throw ProtocolError::workerStalled(
                        $handle->workerId,
                        $this->configuration->progressDeadlineSeconds,
                        Utf8::tailBytes(\trim($diagnostics), 2_048),
                    );
                }

                continue;
            }

            $entry = $this->entriesById[(string) $handle->inFlight] ?? null;
            $budget = $entry?->definition->execution->timeoutSeconds;

            if ($budget === null) {
                continue;
            }

            $deadline = $handle->inFlightAttemptSince + $budget * self::TIMEOUT_GRACE_FACTOR + self::TIMEOUT_GRACE_FLAT_SECONDS;

            if ($this->monotonicTime() > $deadline) {
                $this->containTimeout($handle, $sink, $budget);
            }
        }
    }

    /**
     * @throws AttachmentError
     */
    private function containTimeout(WorkerState $handle, EventSink $sink, float $budget): void
    {
        $inFlight = $handle->inFlight;

        if ($inFlight instanceof TestId) {
            $duration = \max(0.0, $this->monotonicTime() - $handle->inFlightSince);
            $message = \sprintf(
                'The test exceeded its %.3f-second time limit. Greenlight stopped worker "%s" after %.3f seconds.',
                $budget,
                $handle->workerId,
                $duration,
            );
            $diagnostics = \trim($this->transport->diagnostics($handle->workerId));

            if ($diagnostics !== '') {
                $message .= "\nWorker output:\n" . Utf8::tailBytes($diagnostics, 2_048);
            }

            $this->recordSyntheticResult($handle, $sink, new TestResult(
                $inFlight,
                Outcome::Failed,
                $duration,
                0,
                failures: [new FailureDetail($message)],
            ));
        }

        $this->retireFailedWorker($handle, true);
    }

    /**
     * @throws AttachmentError
     */
    private function containCrash(WorkerState $handle, EventSink $sink, string $reason): void
    {
        $inFlight = $handle->inFlight;

        if ($inFlight instanceof TestId) {
            $diagnostics = \trim($this->transport->diagnostics($handle->workerId));

            $this->recordSyntheticResult($handle, $sink, new TestResult(
                $inFlight,
                Outcome::Errored,
                0.0,
                0,
                error: ThrowableDetail::fromThrowable(WorkerError::crashedDuringTest(
                    $handle->workerId,
                    $reason,
                    Utf8::tailBytes($diagnostics, 2_048),
                )),
            ));
        }

        $this->retireFailedWorker($handle);
    }

    /**
     * @throws AttachmentError
     */
    private function recordSyntheticResult(WorkerState $handle, EventSink $sink, TestResult $result): void
    {
        $result = $result->withAttempts(\max($result->attempts, $handle->inFlightAttempt));

        if ($this->configuration->artifactStore instanceof ArtifactStore) {
            $result = $this->configuration->artifactStore->recover($result);
        }

        $handle->inFlight = null;
        $handle->finished[(string) $result->id] = true;
        unset($this->entriesById[(string) $result->id]);
        $this->summary = $this->summary->add($result->outcome);
        $sink->emit(new TestFinished($result, \microtime(true)));
        $this->enforceFailureLimit();
    }

    private function retireFailedWorker(WorkerState $handle, bool $kill = false): void
    {
        $remainder = $handle->unfinished();
        $this->releaseAssignment($handle);
        $this->finishHandle($handle, $kill);
        $this->enqueueRemainder($remainder);
    }

    /**
     * @param list<TestId> $ids
     */
    private function enqueueRemainder(array $ids): void
    {
        if ($ids === [] || $this->draining) {
            return;
        }

        $byClass = [];

        foreach ($ids as $id) {
            $entry = $this->entriesById[(string) $id] ?? null;

            if ($entry === null) {
                continue;
            }

            if ($entry->definition->scheduling->isolated) {
                $this->resourceScheduler()->requeue(new SchedulingUnit(new ExecutionPlan([$entry]), true));

                continue;
            }

            $byClass[$entry->id->class][] = $entry;
        }

        foreach ($byClass as $entries) {
            $this->resourceScheduler()->requeue(new SchedulingUnit(new ExecutionPlan($entries), false));
        }
    }

    private function releaseAssignment(WorkerState $handle): void
    {
        $lease = $handle->lease;

        if (!$lease instanceof ResourceLease) {
            return;
        }

        $this->resourceScheduler()->release($lease);
        $handle->finishAssignment();
        $this->retryWaitingWorkers = true;
    }

    /**
     * @throws AttachmentError
     */
    private function assignWaiting(EventSink $sink): void
    {
        if (!$this->retryWaitingWorkers) {
            return;
        }

        $this->retryWaitingWorkers = false;

        foreach ($this->handles as $handle) {
            if (!$handle->isActive() || !$handle->connected || $handle->assigned !== null) {
                continue;
            }

            $this->assignNext($handle, $sink);
        }
    }

    private function resourceScheduler(): ResourceScheduler
    {
        if (!$this->scheduler instanceof ResourceScheduler) {
            throw new \LogicException('The resource scheduler has not been initialized.');
        }

        return $this->scheduler;
    }

    /**
     * @throws ProtocolError
     */
    private function crossCheck(WorkerState $handle, ResultSummary $reported): void
    {
        if ($handle->tally->toWire() !== $reported->toWire()) {
            throw ProtocolError::summaryMismatch(
                $handle->workerId,
                \json_encode($handle->tally->toWire(), \JSON_THROW_ON_ERROR),
                \json_encode($reported->toWire(), \JSON_THROW_ON_ERROR),
            );
        }
    }

    private function finishHandle(WorkerState $handle, bool $force = false): void
    {
        if (isset($this->initialAssignmentsPending[$handle->workerId])) {
            unset($this->initialAssignmentsPending[$handle->workerId]);
            $this->retryWaitingWorkers = true;
        }

        $handle->retiring = true;
        $this->transport->retire($handle->workerId, $force);
    }

    /** @param non-empty-string $workerId */
    private function completeRetirement(string $workerId): void
    {
        $handle = $this->handles[$workerId] ?? null;

        if (!$handle instanceof WorkerState) {
            return;
        }

        $handle->timing->exitObserved($this->monotonicTime());
        $this->reapedWorkerTimings[$handle->workerId] = $handle->timing->snapshot($handle->workerId);
        $this->channels?->release($handle->channelNumber);
        unset($this->handles[$workerId]);
    }

    private function monotonicTime(): float
    {
        return $this->transport->now();
    }
}
