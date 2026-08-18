<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\WireError;
use Greenlight\Runner\Protocol\EventRegistry;
use Greenlight\Runner\Protocol\Message;
use Greenlight\Runner\Protocol\ProtocolError;

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
     * @throws InvalidWirePayload
     * @throws WireError
     */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(EventRegistry::fromTagged($payload));
    }
}
