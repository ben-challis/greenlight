<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Protocol\Messages;

use Greenlight\Event\Event;
use Greenlight\Execution\ProcessPool\Protocol\EventRegistry;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Sends one execution event from a worker to the orchestrator.
 *
 * @internal
 */
final readonly class EventEnvelope implements Message
{
    public function __construct(public Event $event) {}

    #[\Override]
    public static function tag(): string
    {
        return 'event';
    }

    /**
     * @throws ProtocolError
     */
    #[\Override]
    public function toWire(): array
    {
        return EventRegistry::toTagged($this->event);
    }

    /**
     * @throws ProtocolError
     * @throws WireCommunicationFailed
     */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(EventRegistry::fromTagged($payload));
    }
}
