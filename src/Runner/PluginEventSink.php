<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Event\Event;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Runner\Worker\EventSink;

/**
 * Sends each event to orchestrator run subscribers before it sends the event
 * to the next sink.
 *
 * A throwable from a subscriber propagates. Thus, an orchestrator plugin
 * failure fails the run.
 *
 * @internal
 */
final readonly class PluginEventSink implements EventSink
{
    public function __construct(
        private PluginRegistry $plugins,
        private EventSink $inner,
    ) {}

    #[\Override]
    public function emit(Event $event): void
    {
        foreach ($this->plugins->runSubscribers() as $subscriber) {
            $subscriber->onRunEvent($event);
        }

        $this->inner->emit($event);
    }
}
