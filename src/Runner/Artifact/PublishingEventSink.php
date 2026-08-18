<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Runner\Worker\EventSink;

/**
 * Publishes staged attachments before it sends a completed test event.
 *
 * @internal
 */
final readonly class PublishingEventSink implements EventSink
{
    public function __construct(
        private ArtifactStore $store,
        private EventSink $inner,
    ) {}

    /**
     * @throws AttachmentError
     */
    #[\Override]
    public function emit(Event $event): void
    {
        if ($event instanceof TestFinished) {
            $event = new TestFinished(
                $this->store->publish($event->result),
                $event->occurredAt,
            );
        }

        $this->inner->emit($event);
    }
}
