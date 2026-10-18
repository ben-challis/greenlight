<?php

declare(strict_types=1);

namespace Greenlight\Event;

/**
 * Defines an event that can occur in the run lifecycle.
 *
 * Greenlight creates every event. Plugins and reporters receive events and
 * read them. Do not implement this interface: the worker protocol, the event
 * codec, and the JSONL reporter reject an event type that Greenlight does not
 * define.
 *
 * @phpstan-sealed WireEvent
 */
interface Event
{
    /**
     * Returns a Unix timestamp with microsecond precision.
     */
    public function occurredAt(): float;
}
