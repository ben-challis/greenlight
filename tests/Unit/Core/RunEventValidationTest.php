<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final readonly class RunEventValidationTest
{
    /**
     * @param \Closure(): (RunStarted|RunFinished) $create
     */
    #[Test]
    #[DataSet('validRunEvents')]
    public function runEventsRetainAZeroRunId(\Closure $create): void
    {
        $event = $create();
        $decoded = match ($event::class) {
            RunStarted::class => RunStarted::fromWire(JsonWire::roundTrip($event->toWire())),
            RunFinished::class => RunFinished::fromWire(JsonWire::roundTrip($event->toWire())),
        };

        Expect::that($event->runId)
            ->because('a run event MUST retain each non-empty run ID')
            ->toBe('0')
            ->and($decoded->runId)
            ->because('the run ID MUST survive the wire')
            ->toBe('0');
    }

    #[Test]
    #[DataSet('invalidRunStarts')]
    public function runStartRejectsInvalidIdentityAndCounts(
        string $runId,
        int $plannedTests,
        int $workers,
        string $message,
    ): void {
        Expect::that(static fn(): RunStarted => new RunStarted(
            $runId,
            $plannedTests,
            $workers,
            1.0,
        ))
            ->because('a run start MUST identify a run and use valid counts')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, int, int, string}>
     */
    public static function invalidRunStarts(): iterable
    {
        yield 'empty run ID' => [
            '',
            0,
            1,
            'RunStarted requires a non-empty run ID.',
        ];
        yield 'negative planned tests' => [
            'run-1',
            -1,
            1,
            'RunStarted requires a non-negative planned test count. Actual value: -1.',
        ];
        yield 'zero workers' => [
            'run-1',
            0,
            0,
            'RunStarted requires at least one worker. Actual value: 0.',
        ];
        yield 'negative workers' => [
            'run-1',
            0,
            -1,
            'RunStarted requires at least one worker. Actual value: -1.',
        ];
    }

    /**
     * @return iterable<string, array{\Closure(): (RunStarted|RunFinished)}>
     */
    public static function validRunEvents(): iterable
    {
        yield 'started' => [
            static fn(): RunStarted => new RunStarted('0', 0, 1, 1.0),
        ];

        yield 'finished' => [
            static fn(): RunFinished => new RunFinished('0', new ResultSummary(), 0.0, 1.0),
        ];
    }

    #[Test]
    #[DataSet('invalidRunFinishes')]
    public function runFinishRejectsInvalidIdentityAndDuration(
        string $runId,
        float $durationSeconds,
        string $message,
    ): void {
        Expect::that(static fn(): RunFinished => new RunFinished(
            $runId,
            new ResultSummary(),
            $durationSeconds,
            1.0,
        ))
            ->because('a run finish MUST identify a run and use a non-negative duration')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, float, string}>
     */
    public static function invalidRunFinishes(): iterable
    {
        yield 'empty run ID' => [
            '',
            0.0,
            'RunFinished requires a non-empty run ID.',
        ];
        yield 'negative duration' => [
            'run-1',
            -0.1,
            'RunFinished duration cannot be negative.',
        ];
    }
}
