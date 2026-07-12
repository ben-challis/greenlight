<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Event\Event;

/**
 * Orchestrator-side observation of the event stream.
 *
 * onRunEvent() receives run, worker, suite, class, and test events in arrival
 * order.
 *
 * Observation is read-only; results cannot be altered from this side of the
 * process boundary.
 */
interface RunLifecycleSubscriber
{
    public function onRunEvent(Event $event): void;
}
