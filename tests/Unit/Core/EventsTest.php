<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
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
            new RunStarted('run-1', 100, 8, $at, '/project/build/greenlight-artifacts/run-1'),
            new RunFinished('run-1', $summary, 12.5, $at),
            new SuiteStarted('unit', $at),
            new SuiteFinished('unit', $at),
            new TestClassStarted('App\FooTest', $at, isolated: true),
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

    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[DataSet('runAndSuiteEvents')]
    public function runAndSuiteEventsKeepTheirPublishedWireFields(
        string $kind,
        Event $event,
        array $payload,
    ): void {
        Expect::that($event->toWire())
            ->because(\sprintf('%s events MUST preserve their published fields', $kind))
            ->toBe($payload);
    }

    /**
     * @return iterable<string, array{non-empty-string, Event, array<string, mixed>}>
     */
    public static function runAndSuiteEvents(): iterable
    {
        $at = 1_780_000_000.123456;

        yield 'run started' => [
            'run-started',
            new RunStarted(
                'run-1',
                100,
                8,
                $at,
                '/project/build/greenlight-artifacts/run-1',
            ),
            [
                'runId' => 'run-1',
                'plannedTests' => 100,
                'workers' => 8,
                'occurredAt' => $at,
                'artifactsDirectory' => '/project/build/greenlight-artifacts/run-1',
            ],
        ];
        yield 'run finished' => [
            'run-finished',
            new RunFinished(
                'run-1',
                new ResultSummary(passed: 3, failed: 1, errored: 2, skipped: 4),
                12.5,
                $at,
            ),
            [
                'runId' => 'run-1',
                'summary' => [
                    'passed' => 3,
                    'failed' => 1,
                    'errored' => 2,
                    'skipped' => 4,
                ],
                'durationSeconds' => 12.5,
                'occurredAt' => $at,
            ],
        ];
        yield 'suite started' => [
            'suite-started',
            new SuiteStarted('unit', $at),
            [
                'suite' => 'unit',
                'occurredAt' => $at,
            ],
        ];
        yield 'suite finished' => [
            'suite-finished',
            new SuiteFinished('unit', $at),
            [
                'suite' => 'unit',
                'occurredAt' => $at,
            ],
        ];
    }

    #[Test]
    public function legacyEventPayloadsDefaultNewOptionalFields(): void
    {
        $started = TestClassStarted::fromWire([
            'class' => 'App\FooTest',
            'occurredAt' => 1_780_000_000.1,
        ]);
        $finished = TestClassFinished::fromWire([
            'class' => 'App\FooTest',
            'occurredAt' => 1_780_000_000.2,
        ]);
        $run = RunStarted::fromWire([
            'runId' => 'run-1',
            'plannedTests' => 1,
            'workers' => 1,
            'occurredAt' => 1_780_000_000.0,
        ]);

        Expect::that($started->workerId)
            ->because('legacy class-started events have no worker attribution')
            ->toBe('');
        Expect::that($started->isolated)
            ->because('legacy class-started events have no isolation marker')
            ->toBeFalse();
        Expect::that($finished->workerId)
            ->because('legacy class-finished events have no worker attribution')
            ->toBe('');
        Expect::that($run->artifactsDirectory)
            ->because('legacy run-started events have no artifacts directory')
            ->toBeNull();
    }

    #[Test]
    public function runFinishedCarriesSummarySemantics(): void
    {
        $summary = new ResultSummary()
            ->add(Outcome::Passed)
            ->add(Outcome::Passed)
            ->add(Outcome::Failed)
            ->add(Outcome::Skipped);

        Expect::that($summary->total())->because('run finished carries summary semantics')->toBe(4);
        Expect::that($summary->passed)->because('run finished carries summary semantics')->toBe(2);
        Expect::that($summary->isSuccessful())->because('run finished carries summary semantics')->toBeFalse();
        Expect::that(new ResultSummary(passed: 1, skipped: 2)->isSuccessful())->because('run finished carries summary semantics')->toBeTrue();
    }

    #[Test]
    public function eventsExposeOccurredAtThroughTheInterface(): void
    {
        $event = new SuiteStarted('unit', 123.5);

        $readThroughInterface = static fn(Event $e): float => $e->occurredAt;

        Expect::that($readThroughInterface($event))->because('events expose occurred at through the interface')->toBe(123.5);
    }
}
