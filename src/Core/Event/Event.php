<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Wire\WireSerializable;

/** Add new event types only. Do not replace or remove an event type. */
interface Event extends WireSerializable
{
    /**
     * Returns a Unix timestamp with microsecond precision.
     */
    public float $occurredAt { get; }
}
