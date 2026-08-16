<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\ClassFailureTap;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\CollectingEventSink;

final class ClassFailureTapTest
{
    #[Test]
    public function forwardsLifecycleEventsWithoutRecordingFailures(): void
    {
        $inner = new CollectingEventSink();
        $tap = new ClassFailureTap($inner);
        $event = new RunStarted('run-1', 3, 2, 1.0);

        $tap->emit($event);

        Expect::that($inner->events)
            ->because('the watch tap MUST forward lifecycle events unchanged')
            ->toBe([$event]);
        Expect::that($tap->failedClasses())
            ->toBe([]);
    }

    #[Test]
    public function failedClassesAreDeduplicatedWhileEveryResultIsForwarded(): void
    {
        $inner = new CollectingEventSink();
        $tap = new ClassFailureTap($inner);

        $tap->emit($this->finished('App\AlphaTest', 'passes', Outcome::Passed));
        $tap->emit($this->finished('App\AlphaTest', 'fails', Outcome::Failed));
        $tap->emit($this->finished('App\AlphaTest', 'alsoFails', Outcome::Failed));
        $tap->emit($this->finished('App\BetaTest', 'errors', Outcome::Errored));

        Expect::that($tap->failedClasses())
            ->because('failed classes are deduplicated while every result is forwarded')
            ->toBe(['App\AlphaTest', 'App\BetaTest']);
        Expect::that($inner->results())
            ->toHaveCount(4);
    }

    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     */
    private function finished(string $class, string $method, Outcome $outcome): TestFinished
    {
        return new TestFinished(
            new TestResult(new TestId($class, $method), $outcome, 0.1, 0),
            1.0,
        );
    }
}
