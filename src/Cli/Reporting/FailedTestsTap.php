<?php

declare(strict_types=1);

namespace Greenlight\Cli\Reporting;

use Greenlight\Event\Event;
use Greenlight\Event\EventSink;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Event\TestFinished;

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

    /**
     * @var array<string, array{class: non-empty-string, occurredAt: float}>
     */
    private array $startedClasses = [];

    public function __construct(private readonly EventSink $inner) {}

    #[\Override]
    public function emit(Event $event): void
    {
        if ($event instanceof TestClassStarted) {
            $this->startedClasses[$event->workerId] = [
                'class' => $event->class,
                'occurredAt' => $event->occurredAt,
            ];
        } elseif ($event instanceof TestClassFinished) {
            $this->recordClassDuration($event);
        } elseif ($event instanceof TestFinished) {
            if (!$event->result->outcome->isSuccessful()) {
                $id = (string) $event->result->id;

                if ($id !== '') {
                    $this->failedTests[$id] = true;
                }
            }
        }

        $this->inner->emit($event);
    }

    private function recordClassDuration(TestClassFinished $event): void
    {
        $started = $this->startedClasses[$event->workerId] ?? null;

        if ($started === null || $started['class'] !== $event->class) {
            return;
        }

        unset($this->startedClasses[$event->workerId]);

        if ($event->occurredAt < $started['occurredAt']) {
            return;
        }

        $duration = $event->occurredAt - $started['occurredAt'];

        if (!\is_finite($duration)) {
            $duration = \PHP_FLOAT_MAX;
        }

        $recorded = $this->classSeconds[$event->class] ?? 0.0;
        $this->classSeconds[$event->class] = $recorded > \PHP_FLOAT_MAX - $duration
            ? \PHP_FLOAT_MAX
            : $recorded + $duration;
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
