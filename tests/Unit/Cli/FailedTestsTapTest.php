<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\FailedTestsTap;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\CollectingEventSink;

final class FailedTestsTapTest
{
    #[Test]
    public function recordsFailuresAndClassDurationsWhileForwardingEveryEvent(): void
    {
        $inner = new CollectingEventSink();
        $tap = new FailedTestsTap($inner);
        $events = [
            $this->finished('App\AlphaTest', 'passes', Outcome::Passed, 1.0),
            $this->finished('App\AlphaTest', 'fails', Outcome::Failed, 2.0),
            $this->finished('App\BetaTest', 'errors', Outcome::Errored, 4.0),
            $this->finished('App\AlphaTest', 'fails', Outcome::Failed, 8.0),
            $this->finished('App\BetaTest', 'skips', Outcome::Skipped, 16.0),
        ];

        foreach ($events as $event) {
            $tap->emit($event);
        }

        Expect::that($tap->failedTests())
            ->because('failed reruns MUST contain each unsuccessful test once in encounter order')
            ->toBe([
                'App\AlphaTest::fails',
                'App\BetaTest::errors',
            ])
            ->and($tap->classSeconds())
            ->because('scheduling history MUST accumulate every result duration by class')
            ->toBe([
                'App\AlphaTest' => 11.0,
                'App\BetaTest' => 20.0,
            ])
            ->and($inner->events)
            ->because('the tap MUST forward every event unchanged')
            ->toBe($events);
    }

    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     */
    private function finished(
        string $class,
        string $method,
        Outcome $outcome,
        float $duration,
    ): TestFinished {
        return new TestFinished(
            new TestResult(new TestId($class, $method), $outcome, $duration, 0),
            1.0,
        );
    }
}
