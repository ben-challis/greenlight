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
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\Messages\EventEnvelope;
use Greenlight\Runner\Protocol\Messages\Fatal;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Recycling;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Runner\Worker\EventSink;

/**
 * Workers pull classes on demand; isolated entries use fresh processes.
 * Crashes fail the in-flight test and requeue the rest of its assignment.
 *
 * Bail and graceful shutdown stop new assignments, drain active workers, and
 * reap every process. Queue and within-class order are deterministic, but
 * worker placement depends on load.
 *
 * Deadlines cover workers that never authenticate and authenticated workers
 * that stop making progress outside a running test.
 *
 * @internal
 */
final class Orchestrator
{
    private const float HELLO_DEADLINE_SECONDS = 10.0;
    // Generous on purpose: a worker boot is normally sub-second, but the
    // deadline aborts the whole run, so it must clear the slow tail of a
    // loaded machine with debug extensions, not the typical case.
    private const float CONNECT_DEADLINE_SECONDS = 30.0;
    // Generous for the same reason: the gaps this covers (assignment receipt
    // to first TestStarted, or between tests) are normally milliseconds, but
    // missing the deadline aborts the whole run.
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
     * @param non-empty-list<non-empty-string> $workerCommand argv prefix invoking bin/greenlight
     * @param positive-int|null $recycleAfterTests
     * @param positive-int|null $recycleAboveMemoryBytes
     * @param float $connectDeadlineSeconds seconds a spawned worker gets to complete the hello handshake before the run fails
     * @param float $progressDeadlineSeconds seconds a connected worker may stay silent with no test in flight before the run fails
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
    ) {
        $this->summary = new ResultSummary();
    }

    /**
     * Coverage merged incrementally from worker reports; null when coverage
     * was off or no worker could collect.
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
        $this->scheduler = new ResourceScheduler($pooled, $isolated, $this->resourceLimits);

        if ($this->scheduler->pendingCount() === 0) {
            return $this->summary;
        }

        // Recycling and crash containment legitimately respawn, but never
        // more than a few times per planned test; anything beyond that is a
        // respawn loop and must fail loudly instead of spawning forever.
        $this->spawnBudget = \count($plan->entries) + $workerCount * 8 + 16;

        $token = \bin2hex(\random_bytes(16));
        [$server, $address, $socketPath] = $this->listen();

        try {
            while (true) {
                if (!$this->draining && $this->shutdown?->requested() === true) {
                    $this->drainAll();
                }

                $this->spawnUpTo($workerCount, $address, $token, $sink);

                if ($this->finished()) {
                    break;
                }

                $this->tick($server, $token, $sink);
                $this->ticker?->tick(\microtime(true));
            }
        } finally {
            foreach ($this->handles as $handle) {
                $handle->terminate();
            }

            ErrorTrap::run(static function () use ($server, $socketPath): void {
                \fclose($server);

                if ($socketPath !== null) {
                    \unlink($socketPath);
                    \rmdir(\dirname($socketPath));
                }
            });
        }

        return $this->summary;
    }

    /**
     * @return array{resource, non-empty-string, non-empty-string|null} server, address, unix socket path when used
     */
    private function listen(): array
    {
        // Unix sockets live in the temp dir: sun_path is limited to around a
        // hundred bytes, which deep project paths exceed.
        $socketPath = \rtrim(\sys_get_temp_dir(), '/') . '/greenlight-' . \bin2hex(\random_bytes(6)) . '/orchestrator.sock';

        $server = ErrorTrap::run(static function () use ($socketPath) {
            \mkdir(\dirname($socketPath), 0o700, true);

            return \stream_socket_server('unix://' . $socketPath, $errorCode, $errorMessage);
        });

        if (\is_resource($server)) {
            return [$server, 'unix://' . $socketPath, $socketPath];
        }

        $server = ErrorTrap::run(static function () use (&$errorCode, &$errorMessage) {
            return \stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        });

        if (!\is_resource($server)) {
            throw ProtocolError::malformedFrame('could not open an orchestrator socket: ' . $errorMessage);
        }

        $name = \stream_socket_get_name($server, false);

        if ($name === false || $name === '') {
            throw ProtocolError::malformedFrame('could not resolve the orchestrator socket address');
        }

        return [$server, 'tcp://' . $name, null];
    }

    /**
     * @param positive-int $workerCount
     * @param non-empty-string $address
     * @param non-empty-string $token
     */
    private function spawnUpTo(int $workerCount, string $address, string $token, EventSink $sink): void
    {
        // Isolated workers draw from the same pool as reused ones, and the
        // active-count cap below holds live workers at the worker count, so
        // the bound covers every worker that can be alive at once.
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
                    'spawned %d workers for a plan that should need far fewer; a respawn loop is a bug, not a retry strategy',
                    $this->spawnedCount,
                ));
            }

            $channelNumber = $channels->allocate();
            // proc_open's env parameter replaces the whole environment, so
            // the channel is merged into the parent's rather than passed
            // alone.
            $environment = \getenv();
            $environment['GREENLIGHT_CHANNEL'] = (string) $channelNumber;

            $process = ErrorTrap::run(function () use ($command, $descriptors, &$pipes, $environment) {
                return \proc_open($command, $descriptors, $pipes, $this->workingDirectory, $environment);
            }, $warning);

            if (!\is_resource($process)) {
                $channels->release($channelNumber);

                throw ProtocolError::malformedFrame('could not spawn a worker process', $warning);
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
     * Live workers that have not yet received their first assignment; they
     * will consume queue units, so spawning must not over-provision for the
     * same units.
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
                    // Fresh workers may take isolated units; a reused worker
                    // never does, because isolation promises a process no
                    // other test has touched.
                    $this->assignNext($handle, $sink);

                    continue;
                }
            }

            if ($message !== null || \microtime(true) - $since > self::HELLO_DEADLINE_SECONDS || $channel->isEof()) {
                // Wrong token, unknown worker, or too slow: drop the connection.
                $channel->close();

                continue;
            }

            $still[] = [$channel, $since];
        }

        $this->awaitingHello = $still;
    }

    /**
     * Hands the next queue unit to a connected worker, or drains it when no
     * suitable work remains. A worker may remain connected without an
     * assignment while a resource lease is unavailable.
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
                // Already gone; crash detection covers it.
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
            ));
            $handle->lastProgressAt = \microtime(true);
        } catch (ProtocolError) {
            // The worker died before the assignment arrived; containment
            // re-enqueues the whole unit for a replacement.
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
                        // The worker's cumulative budget is spent; it exits
                        // after Done and a replacement covers the queue.
                        $sink->emit(new WorkerRecycled($handle->workerId, $message->wantsRecycle, \microtime(true)));
                        $this->finishHandle($handle);

                        break;
                    }

                    if ($this->draining || $isolatedAssignment) {
                        try {
                            $channel->send(new Drain());
                        } catch (ProtocolError) {
                            // Already gone after done; nothing left to drain.
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
        }

        if ($event instanceof TestFinished) {
            $handle->inFlight = null;
            $handle->finished[(string) $event->result->id] = true;
            // Finished tests no longer need plan lookups; the index tracks only
            // outstanding tests so it shrinks as the run progresses.
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

    private function drainAll(): void
    {
        $this->draining = true;
        $this->resourceScheduler()->clearPending();

        foreach ($this->handles as $handle) {
            if (!$handle->done && $handle->channel !== null) {
                try {
                    $handle->channel->send(new Drain());
                } catch (ProtocolError) {
                    // The worker is already gone; crash detection covers it.
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
                // Died before it ever connected: nothing was assigned yet,
                // so containment just reaps the handle and the spawn loop
                // provisions a replacement for the still-queued work.
                if (!$handle->isRunning()) {
                    $this->containCrash($handle, $sink, 'the worker exited before connecting');

                    continue;
                }

                // Alive but silent past the deadline: the process exists, so
                // crash containment never fires, and the hello deadline only
                // starts once a connection is accepted. On a starved machine
                // a respawn would stall the same way, so this fails the run
                // rather than burning the spawn budget one deadline at a time.
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

            // pumpChannels drained the channel already, so EOF state is
            // current. Never poll here: a poll that returns a message would
            // silently discard it.
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
                    // The scheduler is intentionally holding this connected
                    // worker until a resource lease becomes available.
                    continue;
                }

                // No per-test budget applies between messages, so a worker
                // that stops responding after receiving an assignment (or
                // between tests) would otherwise hang the run forever. Like a
                // worker that never connects, a stalled worker would probably
                // stall again on respawn, so this fails the run rather than
                // containing it.
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
                'The test exceeded its %.3fs timeout budget; worker "%s" was killed after %.3fs.',
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
            $message = \sprintf('Worker "%s" crashed while running this test: %s.', $handle->workerId, $reason);

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
        if (!$this->retryWaitingWorkers) {
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
            // Give the worker a moment to exit on its own, then reap it.
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
