<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Event\Event;

/**
 * A destination for execution events.
 *
 * In a single process, this is a direct consumer. With a worker pool, it sends
 * events to the orchestrator through the worker protocol.
 *
 * @internal
 */
interface EventSink
{
    public function emit(Event $event): void;
}
