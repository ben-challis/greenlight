<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Core\Event\Event;
use Greenlight\Runner\Protocol\EventRegistry;
use Greenlight\Runner\Protocol\Message;

/**
 * Worker to orchestrator: one execution event.
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

    #[\Override]
    public function toWire(): array
    {
        return EventRegistry::toTagged($this->event);
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(EventRegistry::fromTagged($payload));
    }
}
