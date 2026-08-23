<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Event\Event;
use Greenlight\Event\EventTags;
use Greenlight\Event\WireEvent;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/** @internal */
final class EventRegistry
{
    #[CoverageIgnore]
    private function __construct() {}

    /**
     * @return array<string, mixed>
     * @throws ProtocolError
     */
    public static function toTagged(Event $event): array
    {
        if (!$event instanceof WireEvent) {
            throw ProtocolError::unknownEvent($event::class);
        }

        $tag = EventTags::tagFor($event);

        if ($tag === null) {
            throw ProtocolError::unknownEvent($event::class);
        }

        return ['event' => $tag, 'data' => $event->toWire()];
    }

    /**
     * @param array<string, mixed> $tagged
     *
     * @throws ProtocolError
     * @throws WireCommunicationFailed
     */
    public static function fromTagged(array $tagged): WireEvent
    {
        $tag = Wire::nonEmptyString($tagged, 'event');
        $class = EventTags::classFor($tag);

        if ($class === null) {
            throw ProtocolError::unknownEvent($tag);
        }

        return $class::fromWire(Wire::map($tagged, 'data'));
    }
}
