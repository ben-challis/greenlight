<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Doubles\Fake;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTransport;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTransportEvent;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTransportEventKind;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Drain;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Hello;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;

/**
 * Runs deterministic worker scripts through the orchestrator transport seam.
 */
final class ScriptedWorkerTransport implements Fake, WorkerTransport
{
    private const string TOKEN = 'scripted-transport-token';

    /** @var list<WorkerTransportEvent> */
    private array $events = [];

    /** @var array<int, string> */
    private array $connectionWorkers = [];

    /** @var array<string, true> */
    private array $retiring = [];

    /** @var array<string, true> */
    private array $live = [];

    /** @var array<string, true> */
    private array $disconnectQueued = [];

    /** @var list<array{workerId: string, message: Message}> */
    public array $sent = [];

    /** @var list<array{workerId: string, channel: int}> */
    public array $started = [];

    private float $time = 1.0;

    private int $nextConnectionId = 1;

    private bool $closed = false;

    /**
     * Each outer list supplies messages for one worker in start order. The
     * adapter supplies the hello message before each script.
     *
     * @param list<list<Message>> $scripts
     */
    public function __construct(private array $scripts, private readonly float $pollSeconds = 0.01) {}

    #[\Override]
    public function token(): string
    {
        return self::TOKEN;
    }

    #[\Override]
    public function now(): float
    {
        return $this->time;
    }

    #[\Override]
    public function start(string $workerId, int $channelNumber): int
    {
        $script = \array_shift($this->scripts);

        if (!\is_array($script)) {
            throw ProtocolError::malformedFrame('The scripted transport has no worker script.');
        }

        $this->started[] = ['workerId' => $workerId, 'channel' => $channelNumber];
        $this->live[$workerId] = true;
        $connectionId = $this->nextConnectionId++;
        $this->connectionWorkers[$connectionId] = $workerId;
        $this->events[] = WorkerTransportEvent::connectionAccepted($connectionId);
        $this->events[] = WorkerTransportEvent::connectionMessage(
            $connectionId,
            new Hello($workerId, self::TOKEN, 10_000 + \count($this->started)),
        );

        foreach ($script as $message) {
            $this->events[] = WorkerTransportEvent::workerMessage($workerId, $message);
        }

        return 10_000 + \count($this->started);
    }

    #[\Override]
    public function poll(): array
    {
        $this->time += $this->pollSeconds;

        while (($event = \array_shift($this->events)) instanceof WorkerTransportEvent) {
            if ($event->workerId !== null
                && isset($this->retiring[$event->workerId])
                && $event->kind !== WorkerTransportEventKind::WorkerRetired
            ) {
                continue;
            }

            return [$event];
        }

        return [];
    }

    #[\Override]
    public function resolveConnection(int $connectionId, ?string $workerId): void
    {
        if ($workerId === null) {
            unset($this->connectionWorkers[$connectionId]);

            return;
        }

        if (($this->connectionWorkers[$connectionId] ?? null) !== $workerId) {
            throw new \LogicException('The scripted connection does not belong to the worker.');
        }
    }

    #[\Override]
    public function send(string $workerId, Message $message): void
    {
        if (!isset($this->live[$workerId])) {
            throw ProtocolError::malformedFrame(\sprintf('scripted worker "%s" is not active', $workerId));
        }

        $this->sent[] = ['workerId' => $workerId, 'message' => $message];

        if ($message instanceof Drain && !isset($this->disconnectQueued[$workerId])) {
            $this->disconnectQueued[$workerId] = true;
            $this->events[] = WorkerTransportEvent::workerDisconnected($workerId);
        }
    }

    #[\Override]
    public function retire(string $workerId, bool $force = false): void
    {
        if (!isset($this->live[$workerId]) || isset($this->retiring[$workerId])) {
            return;
        }

        $this->retiring[$workerId] = true;
        $this->events[] = WorkerTransportEvent::workerRetired($workerId);
    }

    #[\Override]
    public function diagnostics(string $workerId): string
    {
        return '';
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
        $this->events = [];
        $this->live = [];
    }

    public function liveWorkerCount(): int
    {
        return \count($this->live) - \count($this->retiring);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
