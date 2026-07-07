<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Runner\Worker\EventSink;

/**
 * Records the ids of failed and errored tests while forwarding the stream,
 * feeding the run state that --failed and failed-first ordering consume.
 *
 * @internal
 */
final class FailedTestsTap implements EventSink
{
    /**
     * @var array<non-empty-string, true>
     */
    private array $failedTests = [];

    public function __construct(
        private readonly EventSink $inner,
    ) {}

    #[\Override]
    public function emit(Event $event): void
    {
        if ($event instanceof TestFinished && !$event->result->outcome->isSuccessful()) {
            $id = (string) $event->result->id;

            if ($id !== '') {
                $this->failedTests[$id] = true;
            }
        }

        $this->inner->emit($event);
    }

    /**
     * @return list<non-empty-string>
     */
    public function failedTests(): array
    {
        return \array_keys($this->failedTests);
    }
}
