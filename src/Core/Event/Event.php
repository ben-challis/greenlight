<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Wire\WireSerializable;

/** Defines an event that can occur in the run lifecycle. */
interface Event extends WireSerializable
{
    /**
     * Returns a Unix timestamp with microsecond precision.
     */
    public float $occurredAt { get; }
}
