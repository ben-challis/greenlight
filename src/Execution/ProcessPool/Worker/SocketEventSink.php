<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Worker;

use Greenlight\Event\Event;
use Greenlight\Event\EventSink;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;

/** @internal */
final readonly class SocketEventSink implements EventSink
{
    public function __construct(private SocketChannel $channel) {}

    /**
     * @throws ProtocolError
     */
    #[\Override]
    public function emit(Event $event): void
    {
        $this->channel->send(new EventEnvelope($event));
    }
}
