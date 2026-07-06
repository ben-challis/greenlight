<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\SuiteFinished;
use Greenlight\Core\Event\SuiteStarted;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\TestStarted;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Tests\Support\Check;

final class EventsTest
{
    #[Test]
    public function everyEventSurvivesTheWire(): void
    {
        $at = 1_780_000_000.123456;
        $id = new TestId('App\FooTest', 'bar');
        $result = new TestResult($id, Outcome::Passed, 0.01, 128);
        $summary = new ResultSummary(passed: 3, failed: 1);

        $events = [
            new RunStarted('run-1', 100, 8, $at),
            new RunFinished('run-1', $summary, 12.5, $at),
            new SuiteStarted('unit', $at),
            new SuiteFinished('unit', $at),
            new TestClassStarted('App\FooTest', $at),
            new TestClassFinished('App\FooTest', $at),
            new TestStarted($id, $at),
            new TestFinished($result, $at),
            new WorkerSpawned('w-1', 4242, $at),
            new WorkerRecycled('w-1', RecycleReason::Memory, $at),
        ];

        foreach ($events as $event) {
            $class = $event::class;
            $restored = $class::fromWire(Check::jsonRoundTrip($event->toWire()));

            Check::same($class, $restored::class, 'restored event class');
            Check::same($event->occurredAt, $restored->occurredAt, $class . ' occurredAt');
            Check::same($event->toWire(), $restored->toWire(), $class . ' payload equality after round trip');
        }
    }

    #[Test]
    public function runFinishedCarriesSummarySemantics(): void
    {
        $summary = new ResultSummary()
            ->add(Outcome::Passed)
            ->add(Outcome::Passed)
            ->add(Outcome::Failed)
            ->add(Outcome::Skipped);

        Check::same(4, $summary->total(), 'total');
        Check::same(2, $summary->passed, 'passed count');
        Check::true(!$summary->isSuccessful(), 'a run with failures to be unsuccessful');
        Check::true(new ResultSummary(passed: 1, skipped: 2)->isSuccessful(), 'skips alone to leave a run successful');
    }

    #[Test]
    public function eventsExposeOccurredAtThroughTheInterface(): void
    {
        $event = new SuiteStarted('unit', 123.5);

        $readThroughInterface = static fn(Event $e): float => $e->occurredAt;

        Check::same(123.5, $readThroughInterface($event), 'interface property access');
    }
}
