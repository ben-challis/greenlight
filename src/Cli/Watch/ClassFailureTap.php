<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use Greenlight\Event\Event;
use Greenlight\Event\EventSink;
use Greenlight\Event\TestFinished;

/** @internal */
final class ClassFailureTap implements EventSink
{
    /**
     * @var array<non-empty-string, true>
     */
    private array $failedClasses = [];

    public function __construct(private readonly EventSink $inner) {}

    #[\Override]
    public function emit(Event $event): void
    {
        if ($event instanceof TestFinished && !$event->result->outcome->isSuccessful()) {
            $this->failedClasses[$event->result->id->class] = true;
        }

        $this->inner->emit($event);
    }

    /**
     * @return list<non-empty-string>
     */
    public function failedClasses(): array
    {
        return \array_keys($this->failedClasses);
    }
}
