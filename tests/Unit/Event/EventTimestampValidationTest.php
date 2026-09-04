<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Event;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Event\Event;
use Greenlight\Event\RunFinished;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Event\TestStarted;
use Greenlight\Event\WorkerSpawned;
use Greenlight\Expect\Expect;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

final readonly class EventTimestampValidationTest
{
    /**
     * @param \Closure(float): Event $create
     */
    #[Test]
    #[DataSet('eventFactories')]
    public function directConstructionRejectsNonfiniteTimestamps(\Closure $create): void
    {
        Expect::that(static fn(): Event => $create(\INF))
            ->because('direct and wire event construction MUST enforce the same timestamp invariant')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use a finite event timestamp.',
            );
    }

    /**
     * @return iterable<string, array{\Closure(float): Event}>
     */
    public static function eventFactories(): iterable
    {
        $id = new TestId('Acme\TimestampTest', 'runs');
        $result = new TestResult($id, Outcome::Passed, 0.0, 0);

        yield 'run started' => [static fn(float $at): Event => new RunStarted('run-1', 1, 1, $at)];
        yield 'run finished' => [static fn(float $at): Event => new RunFinished('run-1', new ResultSummary(), 0.0, $at)];
        yield 'test class started' => [static fn(float $at): Event => new TestClassStarted('Acme\TimestampTest', $at)];
        yield 'test class finished' => [static fn(float $at): Event => new TestClassFinished('Acme\TimestampTest', $at)];
        yield 'test started' => [static fn(float $at): Event => new TestStarted($id, $at)];
        yield 'test finished' => [static fn(float $at): Event => new TestFinished($result, $at)];
        yield 'worker spawned' => [static fn(float $at): Event => new WorkerSpawned('w-1', 1, $at)];
    }
}
