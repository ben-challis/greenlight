<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

use Greenlight\Core\Event\Event;
use Greenlight\Doubles\Fake;
use Greenlight\Plugin\RunLifecycleSubscriber;

final class RecordingRunSubscriber implements RunLifecycleSubscriber, Fake
{
    /**
     * @var list<Event>
     */
    public private(set) array $events = [];

    #[\Override]
    public function onRunEvent(Event $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<string>
     */
    public function sequence(): array
    {
        return \array_map(
            static fn(Event $event): string => new \ReflectionClass($event)->getShortName(),
            $this->events,
        );
    }
}
