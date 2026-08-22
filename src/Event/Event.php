<?php

declare(strict_types=1);

namespace Greenlight\Event;

use Greenlight\Wire\WireSerializable;

/** Defines an event that can occur in the run lifecycle. */
interface Event extends WireSerializable
{
    /**
     * Returns a Unix timestamp with microsecond precision.
     */
    public float $occurredAt { get; }
}
