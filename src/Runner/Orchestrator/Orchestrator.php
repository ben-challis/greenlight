<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\TestStarted;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\GracefulShutdown;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\TestId;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
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
 * Runs the process pool: spawns workers and assigns work class by class on
 * demand.
 *
 * Demand-driven assignment means a worker that finishes early pulls the next
 * class instead of idling behind a static bucket. Isolated entries run on
 * dedicated fresh workers.
 *
 * run() forwards worker event streams to the sink, recycles workers on
 * request (mid-assignment or between assignments once the cumulative budget
 * is spent), and drains everything on bail.
 *
 * Crashes are contained: the in-flight test is attributed to the crash and
 * the remainder of the assignment is reassigned, minus the crashed test. Any
 * bookkeeping mismatch fails loudly.
 *
 * When the injected GracefulShutdown flag reports a request, run() switches
 * to the same drain path used for bail: no further units are assigned,
 * workers finish their in-flight test and report Done, and the finally
 * block reaps processes and removes the socket directory as on any run.
 *
 * Worker placement is load-dependent by design. What stays deterministic is
 * the queue order for a given plan, within-class method order under the
 * seed, and per-class results. The seed reproduces failures, not placement.
 *
 * Every spawned worker gets a channel from a ChannelAllocator bounded by the
 * worker count, exported as GREENLIGHT_CHANNEL in its environment.
 * finishHandle() releases the channel on every path that retires a handle,
 * so replacements reuse freed slots and live workers never share one.
 *
 * @internal
 */
final class Orchestrator
{
    private const float HELLO_DEADLINE_SECONDS = 10.0;
    private const float TIMEOUT_GRACE_FACTOR = 2.0;
    private const float TIMEOUT_GRACE_FLAT_SECONDS = 2.0;

    /**
     * @var list<ExecutionPlan> pooled per-class units, assigned on demand
     */
    private array $queue = [];

    /**
     * @var list<ExecutionPlan> isolated single-entry units, fresh workers only
     */
    private array $isolatedQueue = [];

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

    private int $spawnedCount = 0;

    private int $spawnBudget = 0;

    private ?ChannelAllocator $channels = null;

    /**
     * @param non-empty-list<non-empty-string> $workerCommand argv prefix invoking bin/greenlight
     * @param positive-int|null $recycleAfterTests
     * @param positive-int|null $recycleAboveMemoryBytes
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

        [$this->queue, $this->isolatedQueue] = new Distributor()->units($plan);

        if ($this->queue === [] && $this->isolatedQueue === []) {
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
            }
        } finally {
            foreach ($this->handles as $handle) {
                $handle->terminate();
            }

            @\fclose($server);

            if ($socketPath !== null) {
                @\unlink($socketPath);
                @\rmdir(\dirname($socketPath));
            }
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
        @\mkdir(\dirname($socketPath), 0o700, true);
        $server = @\stream_socket_server('unix://' . $socketPath, $errorCode, $errorMessage);

        if (\is_resource($server)) {
            return [$server, 'unix://' . $socketPath, $socketPath];
        }

        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

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

            $process = @\proc_open($command, $descriptors, $pipes, $this->workingDirectory, $environment);

            if (!\is_resource($process)) {
                $channels->release($channelNumber);

                throw ProtocolError::malformedFrame('could not spawn a worker process');
            }

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
        return array_all($this->handles, fn($handle) => $handle->done);
    }

    private function pendingUnits(): int
    {
        return \count($this->queue) + \count($this->isolatedQueue);
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

        $write = null;
        $except = null;
        @\stream_select($read, $write, $except, 0, 200_000);

        $connection = @\stream_socket_accept($server, 0);

        if (\is_resource($connection)) {
            $this->awaitingHello[] = [new SocketChannel($connection), \microtime(true)];
        }

        $this->processHellos($token, $sink);
        $this->pumpChannels($sink);
        $this->detectCrashes($sink);
        $this->enforceTimeouts($sink);
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
                    // Fresh workers may take isolated units; a reused worker
                    // never does, because isolation promises a process no
                    // other test has touched.
                    $this->assignNext($handle, $sink, allowIsolated: true);

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
     * suitable work remains. Fresh workers (first assignment) may take
     * isolated units once the pooled queue is empty.
     */
    private function assignNext(WorkerHandle $handle, EventSink $sink, bool $allowIsolated): void
    {
        $channel = $handle->channel;

        if (!$channel instanceof SocketChannel) {
            return;
        }

        $unit = null;
        $isolated = false;

        if (!$this->draining) {
            $unit = \array_shift($this->queue);

            if ($unit === null && $allowIsolated) {
                $unit = \array_shift($this->isolatedQueue);
                $isolated = $unit !== null;
            }
        }

        if ($unit === null) {
            try {
                $channel->send(new Drain());
            } catch (ProtocolError) {
                // Already gone; crash detection covers it.
            }

            $this->finishHandle($handle);

            return;
        }

        $handle->beginAssignment($unit, $isolated);

        try {
            $channel->send(new Assign(
                $unit,
                $this->recycleAfterTests,
                $this->recycleAboveMemoryBytes,
                $this->coverageSettings?->includePaths,
                $this->coverageSettings?->driver,
                $this->configFile === '' ? null : $this->configFile,
                $this->detectLeaks,
                $this->policy,
            ));
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
                if ($message instanceof EventEnvelope) {
                    $this->onEvent($handle, $message->event, $sink);
                } elseif ($message instanceof Recycling) {
                    $this->mergeCoverage($message->coverage);
                    $sink->emit(new WorkerRecycled($handle->workerId, $message->reason, \microtime(true)));
                    $this->finishHandle($handle);
                    $this->enqueueRemainder($message->remaining);

                    break;
                } elseif ($message instanceof Done) {
                    $this->crossCheck($handle, $message);
                    $this->mergeCoverage($message->coverage);
                    $this->leaks = [...$this->leaks, ...$message->leaks];

                    if ($message->wantsRecycle instanceof RecycleReason) {
                        // The worker's cumulative budget is spent; it exits
                        // after Done and a replacement covers the queue.
                        $sink->emit(new WorkerRecycled($handle->workerId, $message->wantsRecycle, \microtime(true)));
                        $this->finishHandle($handle);

                        break;
                    }

                    if ($this->draining || $handle->isolatedAssignment) {
                        try {
                            $channel->send(new Drain());
                        } catch (ProtocolError) {
                            // Already gone after done; nothing left to drain.
                        }

                        $this->finishHandle($handle);

                        break;
                    }

                    $this->assignNext($handle, $sink, allowIsolated: false);

                    if ($handle->done) {
                        break;
                    }
                } elseif ($message instanceof Fatal) {
                    throw new ProtocolError(\sprintf(
                        'Worker "%s" reported a fatal framework error: %s (%s:%d)',
                        $handle->workerId,
                        $message->detail->message,
                        $message->detail->file,
                        $message->detail->line,
                    ));
                }
            }
        }
    }

    private function onEvent(WorkerHandle $handle, Event $event, EventSink $sink): void
    {
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
        $this->queue = [];

        foreach ($this->handles as $handle) {
            if (!$handle->done && $handle->channel !== null) {
                try {
                    $handle->channel->send(new Drain());
                } catch (ProtocolError) {
                    // The worker is already gone; crash detection covers it.
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
            if ($handle->done || $handle->inFlight === null) {
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
                $this->containCrash($handle, $sink, \sprintf(
                    'the test exceeded its %.3fs timeout budget and the worker was killed',
                    $budget,
                ));
            }
        }
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

            $result = new TestResult(
                $inFlight,
                Outcome::Errored,
                0.0,
                0,
                error: ThrowableDetail::fromThrowable(new \RuntimeException($message)),
            );

            $handle->inFlight = null;
            $handle->finished[(string) $inFlight] = true;
            $this->summary = $this->summary->add(Outcome::Errored);
            $sink->emit(new TestFinished($result, \microtime(true)));
        }

        $remainder = $handle->unfinished();
        $sink->emit(new WorkerRecycled($handle->workerId, RecycleReason::Crash, \microtime(true)));
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
                $this->isolatedQueue[] = new ExecutionPlan([$entry]);

                continue;
            }

            $byClass[$entry->id->class][] = $entry;
        }

        foreach ($byClass as $entries) {
            $this->queue[] = new ExecutionPlan($entries);
        }
    }

    private function crossCheck(WorkerHandle $handle, Done $done): void
    {
        if ($handle->tally->toWire() !== $done->summary->toWire()) {
            throw ProtocolError::summaryMismatch(
                $handle->workerId,
                \json_encode($handle->tally->toWire(), \JSON_THROW_ON_ERROR),
                \json_encode($done->summary->toWire(), \JSON_THROW_ON_ERROR),
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

            if ($handle->isRunning()) {
                @\proc_terminate($handle->process, 9);
            }

            @\proc_close($handle->process);
        }
    }
}
