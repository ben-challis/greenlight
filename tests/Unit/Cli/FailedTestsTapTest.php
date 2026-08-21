<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\FailedTestsTap;
use Greenlight\Cli\RunState;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\CollectingEventSink;

final readonly class FailedTestsTapTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function forwardsLifecycleEventsWithoutRecordingRunState(): void
    {
        $inner = new CollectingEventSink();
        $tap = new FailedTestsTap($inner);
        $event = new RunStarted('run-1', 3, 2, 1.0);

        $tap->emit($event);

        Expect::that($inner->events)
            ->because('the tap MUST forward lifecycle events unchanged')
            ->toBe([$event]);
        Expect::that($tap->failedTests())
            ->toBe([]);
        Expect::that($tap->classSeconds())
            ->toBe([]);
    }

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
            ]);
        Expect::that($tap->classSeconds())
            ->because('scheduling history MUST accumulate every result duration by class')
            ->toBe([
                'App\AlphaTest' => 11.0,
                'App\BetaTest' => 20.0,
            ]);
        Expect::that($inner->events)
            ->because('the tap MUST forward every event unchanged')
            ->toBe($events);
    }

    #[Test]
    public function accumulatedDurationsRemainPersistableAtTheFloatLimit(): void
    {
        $tap = new FailedTestsTap(new CollectingEventSink());
        $tap->emit($this->finished('App\ExtremeTest', 'first', Outcome::Failed, \PHP_FLOAT_MAX));
        $tap->emit($this->finished('App\ExtremeTest', 'second', Outcome::Failed, \PHP_FLOAT_MAX));

        Expect::that($tap->classSeconds())
            ->because('accepted durations MUST remain finite when their sum exceeds the float range')
            ->toBe(['App\ExtremeTest' => \PHP_FLOAT_MAX]);

        $state = RunState::forFile($this->tempDirectory->path() . '/overflow-state.json');

        Expect::that($state->record($tap->failedTests(), $tap->classSeconds()))
            ->because('scheduling history and failed IDs MUST remain persistable after duration saturation')
            ->toBeTrue();
        Expect::that($state->failedTests())
            ->toBe([
                'App\ExtremeTest::first',
                'App\ExtremeTest::second',
            ]);
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
