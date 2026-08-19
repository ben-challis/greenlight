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
                message: 'Event timestamp MUST be finite.',
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
        yield 'suite started' => [static fn(float $at): Event => new SuiteStarted('unit', $at)];
        yield 'suite finished' => [static fn(float $at): Event => new SuiteFinished('unit', $at)];
        yield 'test class started' => [static fn(float $at): Event => new TestClassStarted('Acme\TimestampTest', $at)];
        yield 'test class finished' => [static fn(float $at): Event => new TestClassFinished('Acme\TimestampTest', $at)];
        yield 'test started' => [static fn(float $at): Event => new TestStarted($id, $at)];
        yield 'test finished' => [static fn(float $at): Event => new TestFinished($result, $at)];
        yield 'worker spawned' => [static fn(float $at): Event => new WorkerSpawned('w-1', 1, $at)];
        yield 'worker recycled' => [static fn(float $at): Event => new WorkerRecycled('w-1', RecycleReason::TestCount, $at)];
    }
}
