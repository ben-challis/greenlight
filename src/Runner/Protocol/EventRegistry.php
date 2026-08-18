<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\EventTags;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireError;

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
     * @throws InvalidWirePayload
     * @throws WireError
     */
    public static function fromTagged(array $tagged): Event
    {
        $tag = Wire::nonEmptyString($tagged, 'event');
        $class = EventTags::classFor($tag);

        if ($class === null) {
            throw ProtocolError::unknownEvent($tag);
        }

        return $class::fromWire(Wire::map($tagged, 'data'));
    }
}
