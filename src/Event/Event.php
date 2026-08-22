<?php

declare(strict_types=1);

namespace Greenlight\Event;

/** Defines an event that can occur in the run lifecycle. */
interface Event
{
    /**
     * Returns a Unix timestamp with microsecond precision.
     */
    public float $occurredAt { get; }
}
