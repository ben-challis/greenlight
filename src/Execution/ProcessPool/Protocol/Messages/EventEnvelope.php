<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Protocol\Messages;

use Greenlight\Event\Event;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Internal\Event\EventCodecFailed;
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
        try {
            return EventCodec::toTagged($this->event);
        } catch (EventCodecFailed $failure) {
            throw ProtocolError::eventCodecFailed($failure);
        }
    }

    /**
     * @throws ProtocolError
     * @throws WireCommunicationFailed
     */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        try {
            return new self(EventCodec::fromTagged($payload));
        } catch (EventCodecFailed $failure) {
            throw ProtocolError::eventCodecFailed($failure);
        }
    }
}
