<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Event\Event;

/**
 * Observes the orchestrator event stream.
 *
 * `onRunEvent()` receives run, worker, class, and test events in arrival order.
 *
 * The subscriber only observes events and cannot change results.
 */
interface RunLifecycleSubscriber extends Plugin
{
    public function onRunEvent(Event $event): void;
}
