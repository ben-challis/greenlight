<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner\Worker;

use Greenlight\Doubles\Fake;
use Greenlight\Runner\Protocol\Channel;
use Greenlight\Runner\Protocol\Message;

final class FakeWorkerChannel implements Channel, Fake
{
    /** @var list<Message> */
    private array $sent = [];

    /** @var list<float> */
    private array $receiveTimeouts = [];

    private int $receiveCount = 0;

    private bool $eof = false;

    private bool $closed = false;

    /**
     * @param list<Message|null> $incoming
     */
    public function __construct(
        private array $incoming,
        private readonly ?int $eofAfterReceives = null,
    ) {}

    #[\Override]
    public function send(Message $message): void
    {
        $this->sent[] = $message;
    }

    #[\Override]
    public function receive(float $timeoutSeconds): ?Message
    {
        $this->receiveTimeouts[] = $timeoutSeconds;
        ++$this->receiveCount;

        if ($this->receiveCount === $this->eofAfterReceives) {
            $this->eof = true;
        }

        return \array_shift($this->incoming);
    }

    #[\Override]
    public function poll(): ?Message
    {
        return null;
    }

    #[\Override]
    public function isEof(): bool
    {
        return $this->eof;
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * @return list<Message>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * @return list<float>
     */
    public function receiveTimeouts(): array
    {
        return $this->receiveTimeouts;
    }

    public function closed(): bool
    {
        return $this->closed;
    }
}
