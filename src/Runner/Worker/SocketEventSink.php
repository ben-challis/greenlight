<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Core\Event\Event;
use Greenlight\Runner\Protocol\Channel;
use Greenlight\Runner\Protocol\Messages\EventEnvelope;

/** @internal */
final readonly class SocketEventSink implements EventSink
{
    public function __construct(private Channel $channel) {}

    #[\Override]
    public function emit(Event $event): void
    {
        $this->channel->send(new EventEnvelope($event));
    }
}
