<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\TestStarted;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Result\Outcome;
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
 * The process pool: spawns workers, assigns slices, forwards their event
 * streams, recycles on request, contains crashes by attributing the in-flight
 * test and reassigning the remainder (minus the crashed test), and drains
 * everything on bail. Fails loudly on any bookkeeping mismatch.
 *
 * @internal
 */
final class Orchestrator
{
    private const float HELLO_DEADLINE_SECONDS = 10.0;
    private const float TIMEOUT_GRACE_FACTOR = 2.0;
    private const float TIMEOUT_GRACE_FLAT_SECONDS = 2.0;

    /**
     * @var list<ExecutionPlan>
     */
    private array $queue = [];

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

        $this->queue = \array_values(\array_filter(
            new Distributor()->slices($plan, $workerCount),
            static fn(ExecutionPlan $slice): bool => \count($slice) > 0,
        ));

        if ($this->queue === []) {
            return $this->summary;
        }

        $token = \bin2hex(\random_bytes(16));
        [$server, $address, $socketPath] = $this->listen();

        try {
            while (true) {
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
        while (!$this->draining && $this->queue !== [] && $this->activeCount() < $workerCount) {
            $slice = \array_shift($this->queue);
            $workerId = 'w-' . ++$this->spawnedCount;

            $command = [...$this->workerCommand, '__worker', $address, $workerId, $token];
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = @\proc_open($command, $descriptors, $pipes, $this->workingDirectory);

            if (!\is_resource($process)) {
                throw ProtocolError::malformedFrame('could not spawn a worker process');
            }

            \fclose($pipes[0]);

            $handle = new WorkerHandle($workerId, $process, $pipes[1], $pipes[2]);
            $handle->slice = $slice;
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
        if ($this->queue !== [] && !$this->draining) {
            return false;
        }
        return array_all($this->handles, fn($handle) => $handle->done);
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

        $this->processHellos($token);
        $this->pumpChannels($sink);
        $this->detectCrashes($sink);
        $this->enforceTimeouts($sink);
    }

    /**
     * @param non-empty-string $token
     */
    private function processHellos(string $token): void
    {
        $still = [];

        foreach ($this->awaitingHello as [$channel, $since]) {
            $message = $channel->poll();

            if ($message instanceof Hello && $message->token === $token) {
                $handle = $this->handles[$message->workerId] ?? null;

                if ($handle !== null && $handle->channel === null && $handle->slice !== null) {
                    $handle->channel = $channel;
                    $channel->send(new Assign(
                        $handle->slice,
                        $this->recycleAfterTests,
                        $this->recycleAboveMemoryBytes,
                        $this->coverageSettings?->includePaths,
                        $this->coverageSettings?->driver,
                        $this->configFile === '' ? null : $this->configFile,
                        $this->detectLeaks,
                    ));

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
                    $channel->send(new Drain());
                    $this->finishHandle($handle);

                    break;
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
            if ($handle->done || $handle->channel === null) {
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

        $entries = [];

        foreach ($ids as $id) {
            $entry = $this->entriesById[(string) $id] ?? null;

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        if ($entries !== []) {
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
