<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
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

        Expect::that($events)->toHaveCount(2)
            ->and($events[0])->toBeInstanceOf(WorkerSpawned::class)
            ->and($events[1])->toBeInstanceOf(TestClassStarted::class);

        $restoredSpawned = $events[0];
        $restoredStarted = $events[1];

        if (!$restoredSpawned instanceof WorkerSpawned || !$restoredStarted instanceof TestClassStarted) {
            Fail::because(\sprintf(
                'Expected events 0 and 1 to be WorkerSpawned and TestClassStarted, got %s and %s.',
                \get_debug_type($restoredSpawned),
                \get_debug_type($restoredStarted),
            ));
        }

        Expect::that($restoredSpawned->workerId)->toBe('worker-2')
            ->and($restoredSpawned->pid)->toBe(42)
            ->and($restoredStarted->class)->toBe('ExampleTest')
            ->and($restoredStarted->workerId)->toBe('worker-2');
    }

    #[Test]
    public function emptyStdoutProducesNoEvents(): void
    {
        Expect::that(JsonlEvents::from(new ProcessResult(0, '', 'diagnostic')))->toBe([]);
    }

    #[Test]
    public function malformedJsonNamesItsStdoutLine(): void
    {
        $valid = $this->line('worker-spawned', new WorkerSpawned('worker-1', 1, 1.0)->toWire());
        $result = new ProcessResult(0, $valid . "\nnot-json", '');

        Expect::that(static fn(): array => JsonlEvents::from($result))
            ->toThrow(\RuntimeException::class, '/stdout line 2/');
    }

    #[Test]
    public function invalidEnvelopesAndPayloadsFailLoudly(): void
    {
        $invalid = [
            '{"v":3,"event":"worker-spawned","data":{}}',
            '{"v":2,"event":"unknown","data":{}}',
            '{"v":2,"event":"worker-spawned","data":{"workerId":"worker-1","pid":"bad","occurredAt":1}}',
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
            ['v' => 2, 'event' => $event, 'data' => $data],
            \JSON_THROW_ON_ERROR,
        );
    }
}
