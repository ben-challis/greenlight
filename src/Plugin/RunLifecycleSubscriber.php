<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Event\Event;

/**
 * Observes the orchestrator event stream.
 *
 * onRunEvent() receives run, worker, suite, class, and test events in the
 * order that they arrive.
 *
 * The subscriber only observes events and cannot change results.
 */
interface RunLifecycleSubscriber extends Plugin
{
    public function onRunEvent(Event $event): void;
}
