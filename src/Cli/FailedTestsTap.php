<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Runner\Worker\EventSink;

/**
 * Records the ids of failed and errored tests plus per-class durations while
 * forwarding the stream, feeding the run state that --failed, failed-first
 * ordering, and longest-first scheduling consume.
 *
 * @internal
 */
final class FailedTestsTap implements EventSink
{
    /**
     * @var array<non-empty-string, true>
     */
    private array $failedTests = [];

    /**
     * @var array<non-empty-string, float>
     */
    private array $classSeconds = [];

    public function __construct(
        private readonly EventSink $inner,
    ) {}

    #[\Override]
    public function emit(Event $event): void
    {
        if ($event instanceof TestFinished) {
            $class = $event->result->id->class;
            $this->classSeconds[$class] = ($this->classSeconds[$class] ?? 0.0) + $event->result->durationSeconds;

            if (!$event->result->outcome->isSuccessful()) {
                $id = (string) $event->result->id;

                if ($id !== '') {
                    $this->failedTests[$id] = true;
                }
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

    /**
     * @return array<non-empty-string, float>
     */
    public function classSeconds(): array
    {
        return $this->classSeconds;
    }
}
