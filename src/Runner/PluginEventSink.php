<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Core\Event\Event;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Runner\Worker\EventSink;

/**
 * Feeds orchestrator-side run subscribers before forwarding each event.
 * Subscriber throwables bubble: an orchestrator-side plugin failure fails
 * the run loudly rather than being swallowed.
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
