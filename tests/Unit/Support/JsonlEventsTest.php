<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonlEvents;
use Greenlight\Tests\Support\ProcessResult;

final class JsonlEventsTest
{
    #[Test]
    public function restoresTypedEventsInOrderFromStdoutOnly(): void
    {
        $spawned = new WorkerSpawned('worker-2', 42, 1.25);
        $started = new TestClassStarted('ExampleTest', 1.5, 'worker-2');
        $result = new ProcessResult(
            0,
            $this->line('worker-spawned', $spawned->toWire())
                . "\n"
                . $this->line('class-started', $started->toWire())
                . "\n",
            'stderr is not part of the JSONL stream',
        );

        $events = JsonlEvents::from($result);

        Expect::that($events)->because('restores typed events in order from stdout only')->toHaveCount(2);
        Expect::that($events[0])->toBeInstanceOf(WorkerSpawned::class);
        Expect::that($events[1])->toBeInstanceOf(TestClassStarted::class);

        $restoredSpawned = $events[0];
        $restoredStarted = $events[1];

        Expect::that($restoredSpawned->workerId)->because('restores typed events in order from stdout only')->toBe('worker-2');
        Expect::that($restoredSpawned->pid)->toBe(42);
        Expect::that($restoredStarted->class)->toBe('ExampleTest');
        Expect::that($restoredStarted->workerId)->toBe('worker-2');
    }

    #[Test]
    public function emptyStdoutProducesNoEvents(): void
    {
        Expect::that(JsonlEvents::from(new ProcessResult(0, '', 'diagnostic')))->because('empty stdout produces no events')->toBe([]);
    }

    #[Test]
    public function finishedTestIdsPreserveEventOrderAndIgnoreOtherEvents(): void
    {
        $first = new TestFinished(new TestResult(new TestId('AlphaTest', 'one'), Outcome::Passed, 0.1, 0), 1.0);
        $spawned = new WorkerSpawned('worker-1', 42, 1.1);
        $second = new TestFinished(new TestResult(new TestId('BetaTest', 'two', 'row'), Outcome::Passed, 0.2, 0), 1.2);
        $result = new ProcessResult(
            0,
            $this->line('test-finished', $first->toWire())
                . "\n"
                . $this->line('worker-spawned', $spawned->toWire())
                . "\n"
                . $this->line('test-finished', $second->toWire()),
            '',
        );

        Expect::that(JsonlEvents::finishedTestIds($result))
            ->because('finished test extraction MUST preserve JSONL event order')
            ->toBe(['AlphaTest::one', 'BetaTest::two[row]']);
    }

    #[Test]
    public function spawnedWorkerIdsPreserveEventOrderAndIgnoreOtherEvents(): void
    {
        $events = [
            new WorkerSpawned('worker-2', 42, 1.0),
            new TestClassStarted('ExampleTest', 1.1, 'worker-2'),
            new WorkerSpawned('worker-1', 43, 1.2),
        ];

        Expect::that(JsonlEvents::spawnedWorkerIds($events))
            ->because('spawned worker extraction MUST preserve event order')
            ->toBe(['worker-2', 'worker-1']);
    }

    #[Test]
    public function malformedJsonNamesItsStdoutLine(): void
    {
        $valid = $this->line('worker-spawned', new WorkerSpawned('worker-1', 1, 1.0)->toWire());
        $result = new ProcessResult(0, $valid . "\nnot-json", '');

        Expect::that(static fn(): array => JsonlEvents::from($result))->because('malformed JSON names its stdout line')
            ->toThrow(\RuntimeException::class, '/stdout line 2/');
    }

    #[Test]
    public function invalidEnvelopesAndPayloadsFailLoudly(): void
    {
        $invalid = [
            '{"v":3,"event":"worker-spawned","data":{}}',
            '{"v":3,"event":"unknown","data":{}}',
            '{"v":3,"event":"worker-spawned","data":{"workerId":"worker-1","pid":"bad","occurredAt":1}}',
        ];

        foreach ($invalid as $line) {
            $result = new ProcessResult(0, $line, '');

            Expect::that(static fn(): array => JsonlEvents::from($result))
                ->toThrow(\RuntimeException::class, '/Invalid Greenlight JSONL/');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function line(string $event, array $data): string
    {
        return \json_encode(
            ['v' => 3, 'event' => $event, 'data' => $data],
            \JSON_THROW_ON_ERROR,
        );
    }
}
