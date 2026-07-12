<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\TestResult;
use Greenlight\Runner\Worker\EventSink;

final class CollectingEventSink implements EventSink
{
    /**
     * @var list<Event>
     */
    public private(set) array $events = [];

    #[\Override]
    public function emit(Event $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<TestResult>
     */
    public function results(): array
    {
        $results = [];

        foreach ($this->events as $event) {
            if ($event instanceof TestFinished) {
                $results[] = $event->result;
            }
        }

        return $results;
    }

    /**
     * @return list<string> event class short names in emission order
     */
    public function sequence(): array
    {
        return \array_map(
            static fn(Event $event): string => new \ReflectionClass($event)->getShortName(),
            $this->events,
        );
    }
}
