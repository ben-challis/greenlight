<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Core\Event\Event;

/**
 * Where execution events go.
 *
 * In a single process this is a direct consumer; with a process pool it
 * becomes the wire back to the orchestrator.
 *
 * @internal
 */
interface EventSink
{
    public function emit(Event $event): void;
}
