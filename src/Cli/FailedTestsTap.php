<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Runner\Worker\EventSink;

/**
 * Records failed test IDs and class durations while it forwards the stream.
 *
 * emit() sends each event to the inner sink. It records the test IDs of failed
 * and errored tests. It also records each test-class duration.
 *
 * failedTests() and classSeconds() supply run state. --failed, failed-first
 * order, and longest-first order use this state.
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

    public function __construct(private readonly EventSink $inner) {}

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
