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
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

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
            $restored = $class::fromWire(JsonWire::roundTrip($event->toWire()));

            Expect::that($restored::class)->toBe($class);
            Expect::that($restored->occurredAt)->toBe($event->occurredAt);
            Expect::that($restored->toWire())->toBe($event->toWire());
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

        Expect::that($summary->total())->toBe(4);
        Expect::that($summary->passed)->toBe(2);
        Expect::that($summary->isSuccessful())->toBeFalse();
        Expect::that(new ResultSummary(passed: 1, skipped: 2)->isSuccessful())->toBeTrue();
    }

    #[Test]
    public function eventsExposeOccurredAtThroughTheInterface(): void
    {
        $event = new SuiteStarted('unit', 123.5);

        $readThroughInterface = static fn(Event $e): float => $e->occurredAt;

        Expect::that($readThroughInterface($event))->toBe(123.5);
    }
}
