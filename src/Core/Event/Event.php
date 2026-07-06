<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Wire\WireSerializable;

/**
 * A run event. The set of events is closed and may only grow additively.
 *
 * @internal
 */
interface Event extends WireSerializable
{
    /**
     * Unix timestamp with microsecond precision.
     */
    public float $occurredAt { get; }
}
