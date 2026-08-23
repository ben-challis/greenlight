<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Internal\Wire\WireCommunicationFailed;
use Greenlight\Result\CapturedOutput;

/**
 * Owns native worker processes, sockets, diagnostics, poll operations, and retirement.
 *
 * @internal
 */
final class NativeWorkerTransport implements WorkerTransport
{
    private const float WORKER_EXIT_GRACE_SECONDS = 2.0;

    /** @var array<non-empty-string, WorkerHandle> */
    private array $workers = [];

    /** @var array<int, SocketChannel> */
    private array $connections = [];

    /** @var array<non-empty-string, true> */
    private array $disconnectReported = [];

    private int $nextConnectionId = 1;

    /**
     * @param non-empty-list<non-empty-string> $workerCommand
     * @param non-empty-string $sharedToken
     */
    private function __construct(
        private readonly array $workerCommand,
        private readonly string $workingDirectory,
        private readonly ServerSocket $server,
        private readonly string $sharedToken,
    ) {}

    /**
     * @param non-empty-list<non-empty-string> $workerCommand
     * @throws ProtocolError
     */
    public static function listen(
        array $workerCommand,
        string $workingDirectory,
        ?string $temporaryDirectory = null,
    ): self {
        return new self(
            $workerCommand,
            $workingDirectory,
            ServerSocket::listen($temporaryDirectory),
            \bin2hex(\random_bytes(16)),
        );
    }

    #[\Override]
    public function token(): string
    {
        return $this->sharedToken;
    }

    #[\Override]
    public function now(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    /** @throws ProtocolError */
    #[\Override]
    public function start(string $workerId, int $channelNumber): int
    {
        $command = [...$this->workerCommand, '__worker', $this->server->address, $workerId, $this->sharedToken];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $environment = \getenv();
        $environment['GREENLIGHT_CHANNEL'] = (string) $channelNumber;

        $process = ErrorTrap::run(function () use ($command, $descriptors, &$pipes, $environment) {
            return \proc_open($command, $descriptors, $pipes, $this->workingDirectory, $environment);
        }, $warning);

        if (!\is_resource($process)) {
            throw ProtocolError::malformedFrame('Greenlight did not start a worker process', $warning);
        }

        \assert(isset($pipes[0], $pipes[1], $pipes[2]));
        \fclose($pipes[0]);

        $handle = new WorkerHandle($workerId, $channelNumber, $process, $pipes[1], $pipes[2]);
        $this->workers[$workerId] = $handle;
        $status = \proc_get_status($process);

        return \max(1, $status['pid']);
    }

    /**
     * @throws ProtocolError
     * @throws WireCommunicationFailed
     */
    #[\Override]
    public function poll(): array
    {
        $events = [];
        $read = [$this->server->stream()];

        foreach ($this->connections as $connection) {
            $read[] = $connection->stream();
        }

        foreach ($this->workers as $handle) {
            if ($handle->lifecycle === WorkerLifecycle::Active && $handle->channel !== null) {
                $read[] = $handle->channel->stream();
            }
        }

        $waitMicroseconds = $this->hasRetiringWorkers() ? 10_000 : 200_000;
        $connection = ErrorTrap::run(function () use ($read, $waitMicroseconds) {
            $write = null;
            $except = null;
            \stream_select($read, $write, $except, 0, $waitMicroseconds);

            return \stream_socket_accept($this->server->stream(), 0);
        });

        if (\is_resource($connection)) {
            $connectionId = $this->nextConnectionId++;
            $this->connections[$connectionId] = new SocketChannel($connection);
            $events[] = WorkerTransportEvent::connectionAccepted($connectionId);
        }

        foreach ($this->connections as $connectionId => $channel) {
            while (($message = $channel->poll()) instanceof Message) {
                $events[] = WorkerTransportEvent::connectionMessage($connectionId, $message);
            }

            if ($channel->isEof()) {
                $channel->close();
                unset($this->connections[$connectionId]);
                $events[] = WorkerTransportEvent::connectionClosed($connectionId);
            }
        }

        foreach ($this->workers as $workerId => $handle) {
            $handle->drainPipes();

            if ($handle->lifecycle === WorkerLifecycle::Active && $handle->channel !== null) {
                while (($message = $handle->channel->poll()) instanceof Message) {
                    $events[] = WorkerTransportEvent::workerMessage($workerId, $message);
                }
            }

            if ($handle->lifecycle === WorkerLifecycle::Active
                && !isset($this->disconnectReported[$workerId])
                && (($handle->channel !== null && $handle->channel->isEof()) || !$handle->isRunning())
            ) {
                $this->disconnectReported[$workerId] = true;
                $events[] = WorkerTransportEvent::workerDisconnected($workerId);
            }

            if (!$handle->reap($this->now())) {
                continue;
            }

            unset($this->workers[$workerId], $this->disconnectReported[$workerId]);
            $events[] = WorkerTransportEvent::workerRetired($workerId);
        }

        return $events;
    }

    #[\Override]
    public function resolveConnection(int $connectionId, ?string $workerId): void
    {
        $channel = $this->connections[$connectionId] ?? null;
        unset($this->connections[$connectionId]);

        if (!$channel instanceof SocketChannel) {
            return;
        }

        if ($workerId === null) {
            $channel->close();

            return;
        }

        $handle = $this->workers[$workerId] ?? null;

        if (!$handle instanceof WorkerHandle || $handle->channel instanceof SocketChannel) {
            $channel->close();

            return;
        }

        $handle->channel = $channel;
    }

    /** @throws ProtocolError */
    #[\Override]
    public function send(string $workerId, Message $message): void
    {
        $channel = $this->workers[$workerId]->channel ?? null;

        if (!$channel instanceof SocketChannel) {
            throw ProtocolError::malformedFrame(\sprintf('worker "%s" has no authenticated channel', $workerId));
        }

        $channel->send($message);
    }

    #[\Override]
    public function retire(string $workerId, bool $force = false): void
    {
        $handle = $this->workers[$workerId] ?? null;

        if (!$handle instanceof WorkerHandle) {
            return;
        }

        if ($force) {
            $handle->kill($this->now());
        } else {
            $handle->retire($this->now(), self::WORKER_EXIT_GRACE_SECONDS);
        }
    }

    #[\Override]
    public function diagnostics(string $workerId): string
    {
        $handle = $this->workers[$workerId] ?? null;

        if (!$handle instanceof WorkerHandle) {
            return '';
        }

        $handle->drainPipes();

        return $handle->diagnostics;
    }

    #[\Override]
    public function startOutputCapture(string $workerId, bool $enabled): void
    {
        $handle = $this->workers[$workerId] ?? null;
        $handle?->startOutputCapture($enabled);
    }

    #[\Override]
    public function finishOutputCapture(string $workerId): ?CapturedOutput
    {
        $handle = $this->workers[$workerId] ?? null;

        return $handle?->finishOutputCapture();
    }

    #[\Override]
    public function close(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        foreach ($this->workers as $handle) {
            $handle->terminate();
        }

        $this->connections = [];
        $this->workers = [];
        $this->disconnectReported = [];
        $this->server->close();
    }

    private function hasRetiringWorkers(): bool
    {
        return \array_any(
            $this->workers,
            static fn(WorkerHandle $handle): bool => $handle->isRetiring(),
        );
    }
}
