<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Doubles\Fake;
use Greenlight\Event\TestFinished;
use Greenlight\Event\TestStarted;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTransport;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTransportEvent;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTransportEventKind;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Assign;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Bootstrap;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Done;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Drain;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Hello;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Ready;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;

/**
 * Runs worker behavior that reacts to orchestrator messages.
 *
 * The start, send, and retire callbacks queue worker events. Each poll
 * returns the events that were queued before it, and advances the time. A
 * poll limit stops a run that does not finish.
 */
final class ReactiveWorkerTransport implements Fake, WorkerTransport
{
    private const string TOKEN = 'reactive-transport-token';

    /** @var list<WorkerTransportEvent> */
    private array $events = [];

    /** @var array<int, array{int, \Closure(self): void}> */
    private array $scheduled = [];

    /** @var array<string, int> */
    private array $connections = [];

    /** @var array<string, true> */
    private array $live = [];

    /** @var array<string, string> */
    private array $diagnostics = [];

    /** @var list<string> */
    public array $started = [];

    /** @var list<string> worker ID and message tag of each send, for example "w-1 assign" */
    public array $log = [];

    public int $nowCalls = 0;

    private int $polls = 0;

    private float $time = 1.0;

    private int $nextConnectionId = 1;

    /**
     * @param \Closure(self, non-empty-string): void $onStart
     * @param \Closure(self, non-empty-string, Message): void $onSend
     * @param (\Closure(self, non-empty-string, bool): void)|null $onRetire
     */
    public function __construct(
        private readonly \Closure $onStart,
        private readonly \Closure $onSend,
        private readonly ?\Closure $onRetire = null,
        private readonly float $pollSeconds = 0.01,
        private readonly int $pollLimit = 10_000,
    ) {}

    /**
     * Returns a start callback that connects each worker at once.
     *
     * @return \Closure(self, non-empty-string): void
     */
    public static function connectAtStart(): \Closure
    {
        return static function (self $transport, string $workerId): void {
            $transport->hello($workerId);
        };
    }

    /**
     * Returns a send callback for workers that pass each assigned test.
     *
     * The assignment callback can replace the passing behavior. It returns
     * true when it handled the assignment.
     *
     * @param (\Closure(self, non-empty-string, Assign): bool)|null $onAssign
     * @return \Closure(self, non-empty-string, Message): void
     */
    public static function passingWorkers(?\Closure $onAssign = null): \Closure
    {
        return static function (self $transport, string $workerId, Message $message) use ($onAssign): void {
            if ($message instanceof Bootstrap) {
                $transport->message($workerId, new Ready());
            } elseif ($message instanceof Assign) {
                if ($onAssign instanceof \Closure && $onAssign($transport, $workerId, $message)) {
                    return;
                }

                $transport->pass($workerId, $message);
            } elseif ($message instanceof Drain) {
                $transport->disconnect($workerId);
            }
        };
    }

    /**
     * Queues a passing result for each assigned test and the Done report.
     *
     * @param non-empty-string $workerId
     */
    public function pass(string $workerId, Assign $assign, bool $done = true): void
    {
        foreach ($assign->slice->entries as $entry) {
            $this->message($workerId, new EventEnvelope(new TestStarted($entry->id, 1.0)));
            $this->message($workerId, new EventEnvelope(new TestFinished(
                new TestResult($entry->id, Outcome::Passed, 0.01, 0),
                1.01,
            )));
        }

        if ($done) {
            $this->message($workerId, new Done(new ResultSummary(passed: \count($assign->slice->entries)), 1_024));
        }
    }

    /**
     * Queues the connection and hello of a worker.
     *
     * @param non-empty-string $workerId
     */
    public function hello(string $workerId): void
    {
        $connectionId = $this->nextConnectionId++;
        $this->connections[$workerId] = $connectionId;
        $this->events[] = WorkerTransportEvent::connectionAccepted($connectionId);
        $this->events[] = WorkerTransportEvent::connectionMessage(
            $connectionId,
            new Hello($workerId, self::TOKEN, 10_000 + $connectionId),
        );
    }

    /** @param non-empty-string $workerId */
    public function message(string $workerId, Message $message): void
    {
        $this->events[] = WorkerTransportEvent::workerMessage($workerId, $message);
    }

    /** @param non-empty-string $workerId */
    public function disconnect(string $workerId): void
    {
        $this->events[] = WorkerTransportEvent::workerDisconnected($workerId);
    }

    /** @param non-empty-string $workerId */
    public function setDiagnostics(string $workerId, string $diagnostics): void
    {
        $this->diagnostics[$workerId] = $diagnostics;
    }

    /**
     * Runs an action after the specified number of further polls.
     *
     * @param positive-int $polls
     * @param \Closure(self): void $action
     */
    public function after(int $polls, \Closure $action): void
    {
        $this->scheduled[] = [$this->polls + $polls, $action];
    }

    public function logIndex(string $entry): int
    {
        $index = \array_search($entry, $this->log, true);

        if (!\is_int($index)) {
            throw new \LogicException(\sprintf('The transport log has no entry "%s".', $entry));
        }

        return $index;
    }

    #[\Override]
    public function token(): string
    {
        return self::TOKEN;
    }

    #[\Override]
    public function now(): float
    {
        ++$this->nowCalls;

        return $this->time;
    }

    #[\Override]
    public function start(string $workerId, int $channelNumber): int
    {
        $this->started[] = $workerId;
        $this->live[$workerId] = true;
        ($this->onStart)($this, $workerId);

        return 10_000 + \count($this->started);
    }

    #[\Override]
    public function poll(): array
    {
        if (++$this->polls > $this->pollLimit) {
            throw new \LogicException(\sprintf('The run did not finish within %d polls.', $this->pollLimit));
        }

        $this->time += $this->pollSeconds;
        $due = [];

        foreach ($this->scheduled as $index => [$poll, $action]) {
            if ($poll <= $this->polls) {
                $due[] = $action;
                unset($this->scheduled[$index]);
            }
        }

        foreach ($due as $action) {
            $action($this);
        }

        $events = $this->events;
        $this->events = [];

        return \array_values(\array_filter(
            $events,
            fn(WorkerTransportEvent $event): bool => $event->workerId === null
                || isset($this->live[$event->workerId])
                || $event->kind === WorkerTransportEventKind::WorkerRetired,
        ));
    }

    #[\Override]
    public function resolveConnection(int $connectionId, ?string $workerId): void
    {
        if ($workerId !== null && ($this->connections[$workerId] ?? null) !== $connectionId) {
            throw new \LogicException('The connection does not belong to the worker.');
        }
    }

    #[\Override]
    public function send(string $workerId, Message $message): void
    {
        if (!isset($this->live[$workerId])) {
            throw ProtocolError::malformedFrame(\sprintf('worker "%s" is not active', $workerId));
        }

        $this->log[] = $workerId . ' ' . $message::tag();
        ($this->onSend)($this, $workerId, $message);
    }

    #[\Override]
    public function retire(string $workerId, bool $force = false): void
    {
        if (!isset($this->live[$workerId])) {
            return;
        }

        $this->log[] = $workerId . ($force ? ' kill' : ' retire');

        if ($this->onRetire instanceof \Closure) {
            ($this->onRetire)($this, $workerId, $force);
        }

        unset($this->live[$workerId]);
        $this->events[] = WorkerTransportEvent::workerRetired($workerId);
    }

    #[\Override]
    public function diagnostics(string $workerId): string
    {
        return $this->diagnostics[$workerId] ?? '';
    }

    #[\Override]
    public function close(): void
    {
        $this->events = [];
        $this->live = [];
    }
}
