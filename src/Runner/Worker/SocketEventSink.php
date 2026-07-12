<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Core\Event\Event;
use Greenlight\Runner\Protocol\Messages\EventEnvelope;
use Greenlight\Runner\Protocol\SocketChannel;

/**
 * Streams every event straight to the orchestrator as it happens.
 *
 * @internal
 */
final readonly class SocketEventSink implements EventSink
{
    public function __construct(private SocketChannel $channel) {}

    #[\Override]
    public function emit(Event $event): void
    {
        $this->channel->send(new EventEnvelope($event));
    }
}
