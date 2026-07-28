<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\TestStarted;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\GracefulShutdown;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\TestId;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Reporting\Ticking;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\AttemptStarted;
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\Messages\EventEnvelope;
use Greenlight\Runner\Protocol\Messages\Fatal;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Recycling;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Runner\Resource\MachineResourceCoordinator;
use Greenlight\Runner\Resource\MachineResourcePermit;
use Greenlight\Runner\Worker\EventSink;

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
    private const float CONNECT_DEADLINE_SECONDS = 30.0;
    // These periods usually take milliseconds. They occur between assignment
    // receipt and the first TestStarted or between tests. This deadline stops
    // the complete run, so it permits longer periods.
    private const float PROGRESS_DEADLINE_SECONDS = 60.0;
    private const float TIMEOUT_GRACE_FACTOR = 2.0;
    private const float TIMEOUT_GRACE_FLAT_SECONDS = 2.0;

    private ?ResourceScheduler $scheduler = null;

    /**
     * @var array<string, WorkerHandle>
     */
    private array $handles = [];

    /**
     * @var list<array{SocketChannel, float}> connected but not yet authenticated
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

    private int $spawnedCount = 0;

    private int $spawnBudget = 0;

    private ?ChannelAllocator $channels = null;

    /**
     * @param non-empty-list<non-empty-string> $workerCommand Command prefix that invokes bin/greenlight.
     * @param positive-int|null $recycleAfterTests
     * @param positive-int|null $recycleAboveMemoryBytes
     * @param float $connectDeadlineSeconds Maximum seconds for a new worker to complete the hello handshake.
     * @param float $progressDeadlineSeconds Maximum seconds that a connected worker can stay silent when no test is in flight.
     * @param array<non-empty-string, positive-int> $resourceLimits
     */
    public function __construct(
        private readonly array $workerCommand,
        private readonly string $workingDirectory,
        private readonly ?int $recycleAfterTests = null,
        private readonly ?int $recycleAboveMemoryBytes = null,
        private readonly ?int $stopAfterFailures = null,
        private readonly ?CoverageSettings $coverageSettings = null,
        private readonly ?string $configFile = null,
        private readonly bool $detectLeaks = false,
        private readonly ?ResultPolicy $policy = null,
        private readonly ?GracefulShutdown $shutdown = null,
        private readonly ?Ticking $ticker = null,
        private readonly ?ArtifactStore $artifactStore = null,
        private readonly ?ArtifactConfiguration $artifactConfiguration = null,
        private readonly float $connectDeadlineSeconds = self::CONNECT_DEADLINE_SECONDS,
        private readonly float $progressDeadlineSeconds = self::PROGRESS_DEADLINE_SECONDS,
        private readonly array $resourceLimits = [],
        private readonly ?MachineResourceCoordinator $machineResourceCoordinator = null,
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

    private function mergeCoverage(?CoverageMap $coverage): void
    {
        if (!$coverage instanceof CoverageMap) {
            return;
        }

        $this->coverage = $this->coverage instanceof CoverageMap ? $this->coverage->merge($coverage) : $coverage;
    }

    /**
     * @param positive-int $workerCount
     *
     * @throws ProtocolError
     */
    public function run(ExecutionPlan $plan, EventSink $sink, int $workerCount): ResultSummary
    {
        foreach ($plan->entries as $entry) {
            $this->entriesById[(string) $entry->id] = $entry;
        }

        [$pooled, $isolated] = new Distributor()->units($plan);
        $this->scheduler = new ResourceScheduler(
            $pooled,
            $isolated,
            $this->resourceLimits,
            $this->machineResourceCoordinator,
        );

        if ($this->scheduler->pendingCount() === 0) {
            return $this->summary;
        }

        // Worker replacement and crash containment can start replacement
        // processes. Permit only a small number for each planned test. A
        // larger number indicates a replacement loop and must fail the run.
        $this->spawnBudget = \count($plan->entries) + $workerCount * 8 + 16;

        $token = \bin2hex(\random_bytes(16));
        $server = ServerSocket::listen();

        try {
            while (true) {
                if (!$this->draining && $this->shutdown?->requested() === true) {
                    $this->drainAll();
                }

                $this->spawnUpTo($workerCount, $server->address, $token, $sink);

                if ($this->finished()) {
                    break;
                }

                $this->tick($server->stream(), $token, $sink);
                $this->ticker?->tick(\microtime(true));
            }
        } finally {
            foreach ($this->handles as $handle) {
                $handle->terminate();
            }

            $this->machineResourceCoordinator?->close();
            $server->close();
        }

        return $this->summary;
    }

    /**
     * @param positive-int $workerCount
     * @param non-empty-string $address
     * @param non-empty-string $token
     */
    private function spawnUpTo(int $workerCount, string $address, string $token, EventSink $sink): void
    {
        // Isolated and reused workers use the same worker pool. The active
        // count below limits live workers to the worker count. Thus, the
        // allocator has a channel for each possible live worker.
        $channels = $this->channels ??= new ChannelAllocator($workerCount);

        while (!$this->draining && $this->pendingUnits() > $this->unassignedActiveCount() && $this->activeCount() < $workerCount) {
            $workerId = 'w-' . ++$this->spawnedCount;

            $command = [...$this->workerCommand, '__worker', $address, $workerId, $token];
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            if ($this->spawnedCount > $this->spawnBudget) {
                throw ProtocolError::malformedFrame(\sprintf(
                    'Greenlight started %d workers for this execution plan. '
                    . 'This count indicates a worker replacement loop',
                    $this->spawnedCount,
                ));
            }

            $channelNumber = $channels->allocate();
            // The env parameter of proc_open replaces the complete
            // environment. Add the channel to the parent environment.
            $environment = \getenv();
            $environment['GREENLIGHT_CHANNEL'] = (string) $channelNumber;

            $process = ErrorTrap::run(function () use ($command, $descriptors, &$pipes, $environment) {
                return \proc_open($command, $descriptors, $pipes, $this->workingDirectory, $environment);
            }, $warning);

            if (!\is_resource($process)) {
                $channels->release($channelNumber);

                throw ProtocolError::malformedFrame('Greenlight did not start a worker process', $warning);
            }

            \assert(isset($pipes[0], $pipes[1], $pipes[2]));

            \fclose($pipes[0]);

            $handle = new WorkerHandle($workerId, $channelNumber, $process, $pipes[1], $pipes[2]);
            $this->handles[$workerId] = $handle;

            $status = \proc_get_status($process);
            $sink->emit(new WorkerSpawned($workerId, \max(1, $status['pid']), \microtime(true)));
        }
    }

    private function activeCount(): int
    {
        $active = 0;

        foreach ($this->handles as $handle) {
            if (!$handle->done) {
                ++$active;
            }
        }

        return $active;
    }

    private function finished(): bool
    {
        if ($this->pendingUnits() > 0 && !$this->draining) {
            return false;
        }
        return \array_all($this->handles, fn($handle) => $handle->done);
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
            if (!$handle->done && $handle->assigned === null) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param resource $server
     * @param non-empty-string $token
     */
    private function tick(mixed $server, string $token, EventSink $sink): void
    {
        $read = [$server];

        foreach ($this->awaitingHello as [$channel]) {
            $read[] = $channel->stream();
        }

        foreach ($this->handles as $handle) {
            if (!$handle->done && $handle->channel !== null) {
                $read[] = $handle->channel->stream();
            }
        }

        $connection = ErrorTrap::run(static function () use ($server, $read) {
            $write = null;
            $except = null;
            \stream_select($read, $write, $except, 0, 200_000);

            return \stream_socket_accept($server, 0);
        });

        if (\is_resource($connection)) {
            $this->awaitingHello[] = [new SocketChannel($connection), \microtime(true)];
        }

        $this->processHellos($token, $sink);
        $this->pumpChannels($sink);
        $this->detectCrashes($sink);
        $this->enforceTimeouts($sink);
        $this->assignWaiting($sink);
    }

    /**
     * @param non-empty-string $token
     */
    private function processHellos(string $token, EventSink $sink): void
    {
        $still = [];

        foreach ($this->awaitingHello as [$channel, $since]) {
            $message = $channel->poll();

            if ($message instanceof Hello && $message->token === $token) {
                $handle = $this->handles[$message->workerId] ?? null;

                if ($handle !== null && $handle->channel === null) {
                    $handle->channel = $channel;
                    $handle->lastProgressAt = \microtime(true);
                    // A new worker can take an isolated scheduling unit. A
                    // reused worker cannot take it because isolation requires
                    // an unused process.
                    $this->assignNext($handle, $sink);

                    continue;
                }
            }

            if ($message !== null || \microtime(true) - $since > self::HELLO_DEADLINE_SECONDS || $channel->isEof()) {
                // Close a connection for an incorrect token, unknown worker,
                // or authentication timeout.
                $channel->close();

                continue;
            }

            $still[] = [$channel, $since];
        }

        $this->awaitingHello = $still;
    }

    /**
     * Gives the next suitable queued scheduling unit to a connected worker.
     *
     * If no suitable work remains, drains the worker. A worker can remain
     * connected without an assignment while a resource lease is unavailable.
     */
    private function assignNext(WorkerHandle $handle, EventSink $sink): void
    {
        $channel = $handle->channel;

        if (!$channel instanceof SocketChannel) {
            return;
        }

        $decision = $this->draining
            ? DispatchDecision::drain()
            : $this->resourceScheduler()->dispatch($handle->isFresh());

        if ($decision->kind === DispatchKind::Wait) {
            return;
        }

        if ($decision->kind === DispatchKind::Drain) {
            try {
                $channel->send(new Drain());
            } catch (ProtocolError) {
                // Crash detection processes a worker that is already gone.
            }

            $this->finishHandle($handle);

            return;
        }

        $lease = $decision->lease;

        if (!$lease instanceof ResourceLease) {
            throw new \LogicException('An assign decision must carry a resource lease.');
        }

        $handle->beginAssignment($lease);

        try {
            $machineResourceLeases = $lease->machinePermit instanceof MachineResourcePermit
                ? $lease->machinePermit->coordinationKeys
                : [];

            $channel->send(new Assign(
                $lease->unit->plan,
                $this->recycleAfterTests,
                $this->recycleAboveMemoryBytes,
                $this->coverageSettings?->includePaths,
                $this->coverageSettings?->driver,
                $this->configFile === '' ? null : $this->configFile,
                $this->detectLeaks,
                $this->policy,
                $this->artifactStore?->session(),
                $this->artifactConfiguration,
                $machineResourceLeases,
            ));
            $handle->lastProgressAt = \microtime(true);
        } catch (ProtocolError) {
            // The worker stopped before it received the assignment. Crash
            // containment puts the complete scheduling unit in the queue for
            // a replacement worker.
            $this->containCrash($handle, $sink, 'the worker exited before receiving its assignment');
        }
    }

    private function pumpChannels(EventSink $sink): void
    {
        foreach ($this->handles as $handle) {
            $channel = $handle->channel;

            if ($handle->done || $channel === null) {
                continue;
            }

            $handle->drainPipes();

            while (($message = $channel->poll()) !== null) {
                $handle->lastProgressAt = \microtime(true);

                if ($message instanceof EventEnvelope) {
                    $this->onEvent($handle, $message->event, $sink);
                } elseif ($message instanceof AttemptStarted) {
                    $this->onAttemptStarted($handle, $message);
                } elseif ($message instanceof Recycling) {
                    $this->crossCheck($handle, $message->summary);
                    $this->mergeCoverage($message->coverage);
                    $sink->emit(new WorkerRecycled($handle->workerId, $message->reason, \microtime(true)));
                    $this->releaseAssignment($handle);
                    $this->finishHandle($handle);
                    $this->enqueueRemainder($message->remaining);

                    break;
                } elseif ($message instanceof Done) {
                    $this->crossCheck($handle, $message->summary);
                    $this->mergeCoverage($message->coverage);
                    $this->leaks = [...$this->leaks, ...$message->leaks];
                    $isolatedAssignment = $handle->isolatedAssignment;
                    $this->releaseAssignment($handle);

                    if ($message->wantsRecycle instanceof RecycleReason) {
                        // The worker has used its cumulative budget. It exits
                        // after Done, and a replacement worker processes the
                        // queue.
                        $sink->emit(new WorkerRecycled($handle->workerId, $message->wantsRecycle, \microtime(true)));
                        $this->finishHandle($handle);

                        break;
                    }

                    if ($this->draining || $isolatedAssignment) {
                        try {
                            $channel->send(new Drain());
                        } catch (ProtocolError) {
                            // The worker is already gone after Done. No drain is necessary.
                        }

                        $this->finishHandle($handle);

                        break;
                    }

                    $this->assignNext($handle, $sink);

                    if ($handle->done) {
                        break;
                    }
                } elseif ($message instanceof Fatal) {
                    throw ProtocolError::workerFatal(
                        $handle->workerId,
                        $message->detail->message,
                        $message->detail->file,
                        $message->detail->line,
                    );
                }
            }
        }

    }

    private function onEvent(WorkerHandle $handle, Event $event, EventSink $sink): void
    {
        if ($event instanceof TestFinished && $this->artifactStore instanceof ArtifactStore) {
            $event = new TestFinished(
                $this->artifactStore->publish($event->result),
                $event->occurredAt,
            );
        }

        if ($event instanceof TestStarted) {
            $handle->inFlight = $event->id;
            $handle->inFlightSince = \microtime(true);
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

        if ($event instanceof TestFinished
            && $this->stopAfterFailures !== null
            && !$this->draining
            && $this->summary->failed + $this->summary->errored >= $this->stopAfterFailures
        ) {
            $this->drainAll();
        }
    }

    private function onAttemptStarted(WorkerHandle $handle, AttemptStarted $message): void
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
    }

    private function drainAll(): void
    {
        $this->draining = true;
        $this->resourceScheduler()->clearPending();

        foreach ($this->handles as $handle) {
            if (!$handle->done && $handle->channel !== null) {
                try {
                    $handle->channel->send(new Drain());
                } catch (ProtocolError) {
                    // Crash detection processes a worker that is already gone.
                }

                if ($handle->assigned === null) {
                    $this->finishHandle($handle);
                }
            }
        }
    }

    private function detectCrashes(EventSink $sink): void
    {
        foreach ($this->handles as $handle) {
            if ($handle->done) {
                continue;
            }

            if ($handle->channel === null) {
                // This worker stopped before connection and had no assignment.
                // Crash containment collects the handle. The start loop
                // supplies a replacement worker for queued work.
                if (!$handle->isRunning()) {
                    $this->containCrash($handle, $sink, 'the worker exited before connecting');

                    continue;
                }

                // This process is alive but did not connect before the
                // deadline. Crash containment cannot detect an active process.
                // The hello deadline starts only after connection acceptance.
                // On a computer without sufficient resources, a replacement
                // can stop in the same way. Thus, this condition fails the run
                // and does not use the replacement budget.
                if (\microtime(true) - $handle->spawnedAt > $this->connectDeadlineSeconds) {
                    $handle->drainPipes();
                    $handle->terminate();

                    throw ProtocolError::workerNeverConnected(
                        $handle->workerId,
                        $this->connectDeadlineSeconds,
                        \substr(\trim($handle->diagnostics), -2048),
                    );
                }

                continue;
            }

            // pumpChannels already drained the channel, so the EOF state is
            // current. Do not poll here because another poll discards a
            // returned message.
            if (!$handle->channel->isEof()) {
                continue;
            }

            $this->containCrash($handle, $sink, 'the worker process exited unexpectedly');
        }
    }

    private function enforceTimeouts(EventSink $sink): void
    {
        foreach ($this->handles as $handle) {
            if ($handle->done) {
                continue;
            }

            if ($handle->inFlight === null) {
                if ($handle->assigned === null) {
                    // The scheduler keeps this connected worker idle until a
                    // resource lease is available.
                    continue;
                }

                // No test timeout applies between messages. Thus, a worker can
                // stop after assignment receipt or between tests without a
                // test timeout. A replacement worker can stop in the same way.
                // Fail the run instead of use crash containment.
                if ($handle->channel !== null
                    && \microtime(true) - $handle->lastProgressAt > $this->progressDeadlineSeconds
                ) {
                    $handle->drainPipes();
                    $handle->terminate();

                    throw ProtocolError::workerStalled(
                        $handle->workerId,
                        $this->progressDeadlineSeconds,
                        \substr(\trim($handle->diagnostics), -2048),
                    );
                }

                continue;
            }

            $entry = $this->entriesById[(string) $handle->inFlight] ?? null;
            $budget = $entry?->metadata->timeoutSeconds;

            if ($budget === null) {
                continue;
            }

            $deadline = $handle->inFlightSince + $budget * self::TIMEOUT_GRACE_FACTOR + self::TIMEOUT_GRACE_FLAT_SECONDS;

            if (\microtime(true) > $deadline) {
                $handle->terminate();
                $this->containTimeout($handle, $sink, $budget);
            }
        }
    }

    private function containTimeout(WorkerHandle $handle, EventSink $sink, float $budget): void
    {
        $inFlight = $handle->inFlight;

        if ($inFlight instanceof TestId) {
            $duration = \max(0.0, \microtime(true) - $handle->inFlightSince);
            $message = \sprintf(
                'The test exceeded its %.3f-second time limit. Greenlight stopped worker "%s" after %.3f seconds.',
                $budget,
                $handle->workerId,
                $duration,
            );
            $diagnostics = \trim($handle->diagnostics);

            if ($diagnostics !== '') {
                $message .= "\nWorker output:\n" . \substr($diagnostics, -2048);
            }

            $this->recordSyntheticResult($handle, $sink, new TestResult(
                $inFlight,
                Outcome::Failed,
                $duration,
                0,
                failures: [new FailureDetail($message)],
            ));
        }

        $this->retireFailedWorker($handle, $sink);
    }

    private function containCrash(WorkerHandle $handle, EventSink $sink, string $reason): void
    {
        $inFlight = $handle->inFlight;

        if ($inFlight instanceof TestId) {
            $diagnostics = \trim($handle->diagnostics);
            $message = \sprintf('Worker "%s" crashed during this test: %s.', $handle->workerId, $reason);

            if ($diagnostics !== '') {
                $message .= "\nWorker output:\n" . \substr($diagnostics, -2048);
            }

            $this->recordSyntheticResult($handle, $sink, new TestResult(
                $inFlight,
                Outcome::Errored,
                0.0,
                0,
                error: ThrowableDetail::fromThrowable(new \RuntimeException($message)),
            ));
        }

        $this->retireFailedWorker($handle, $sink);
    }

    private function recordSyntheticResult(WorkerHandle $handle, EventSink $sink, TestResult $result): void
    {
        $result = $result->withAttempts(\max($result->attempts, $handle->inFlightAttempt));

        if ($this->artifactStore instanceof ArtifactStore) {
            $result = $this->artifactStore->recover($result);
        }

        $handle->inFlight = null;
        $handle->finished[(string) $result->id] = true;
        unset($this->entriesById[(string) $result->id]);
        $this->summary = $this->summary->add($result->outcome);
        $sink->emit(new TestFinished($result, \microtime(true)));
    }

    private function retireFailedWorker(WorkerHandle $handle, EventSink $sink): void
    {
        $remainder = $handle->unfinished();
        $sink->emit(new WorkerRecycled($handle->workerId, RecycleReason::Crash, \microtime(true)));
        $this->releaseAssignment($handle);
        $this->finishHandle($handle);
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

            if ($entry->metadata->isolated) {
                $this->resourceScheduler()->requeue(new SchedulingUnit(new ExecutionPlan([$entry]), true));

                continue;
            }

            $byClass[$entry->id->class][] = $entry;
        }

        foreach ($byClass as $entries) {
            $this->resourceScheduler()->requeue(new SchedulingUnit(new ExecutionPlan($entries), false));
        }
    }

    private function releaseAssignment(WorkerHandle $handle): void
    {
        $lease = $handle->lease;

        if (!$lease instanceof ResourceLease) {
            return;
        }

        $this->resourceScheduler()->release($lease);
        $handle->finishAssignment();
        $this->retryWaitingWorkers = true;
    }

    private function assignWaiting(EventSink $sink): void
    {
        if (!$this->retryWaitingWorkers && $this->machineResourceCoordinator?->hasLimits() !== true) {
            return;
        }

        $this->retryWaitingWorkers = false;

        foreach ($this->handles as $handle) {
            if ($handle->done || $handle->channel === null || $handle->assigned !== null) {
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

    private function crossCheck(WorkerHandle $handle, ResultSummary $reported): void
    {
        if ($handle->tally->toWire() !== $reported->toWire()) {
            throw ProtocolError::summaryMismatch(
                $handle->workerId,
                \json_encode($handle->tally->toWire(), \JSON_THROW_ON_ERROR),
                \json_encode($reported->toWire(), \JSON_THROW_ON_ERROR),
            );
        }
    }

    private function finishHandle(WorkerHandle $handle): void
    {
        if (!$handle->done) {
            $this->channels?->release($handle->channelNumber);
        }

        $handle->done = true;
        $handle->drainPipes();
        $handle->channel?->close();

        if (\is_resource($handle->process)) {
            // Permit the worker to exit, and then collect it.
            $deadline = \microtime(true) + 2.0;

            while (\microtime(true) < $deadline && $handle->isRunning()) {
                \usleep(10_000);
            }

            ErrorTrap::run(static function () use ($handle): void {
                if ($handle->isRunning()) {
                    \proc_terminate($handle->process, 9);
                }

                \proc_close($handle->process);
            });
        }
    }
}
