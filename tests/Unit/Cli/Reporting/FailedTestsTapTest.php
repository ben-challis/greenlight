<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Reporting\FailedTestsTap;
use Greenlight\Cli\State\RunState;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\TestId;
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
        Expect::that($tap->retriedPasses())
            ->toBe(0);
    }

    #[Test]
    public function recordsFailuresAndCompleteClassSpansWhileForwardingEveryEvent(): void
    {
        $inner = new CollectingEventSink();
        $tap = new FailedTestsTap($inner);
        $events = [
            new TestClassStarted('App\AlphaTest', 10.0, 'worker-1'),
            $this->finished('App\AlphaTest', 'passes', Outcome::Passed, 1.0),
            $this->finished('App\AlphaTest', 'passesAfterRetry', Outcome::Passed, 1.5, attempts: 2),
            new TestClassStarted('App\BetaTest', 20.0, 'worker-2'),
            $this->finished('App\AlphaTest', 'fails', Outcome::Failed, 2.0),
            new TestClassFinished('App\AlphaTest', 14.0, 'worker-1'),
            $this->finished('App\BetaTest', 'errors', Outcome::Errored, 4.0),
            new TestClassFinished('App\BetaTest', 26.0, 'worker-2'),
            new TestClassStarted('App\AlphaTest', 30.0, 'worker-1', isolated: true),
            $this->finished('App\AlphaTest', 'fails', Outcome::Failed, 8.0),
            new TestClassFinished('App\AlphaTest', 33.5, 'worker-1'),
            $this->finished('App\BetaTest', 'skips', Outcome::Skipped, 16.0, attempts: 2),
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
            ->because('scheduling history MUST include each complete class span')
            ->toBe([
                'App\AlphaTest' => 7.5,
                'App\BetaTest' => 6.0,
            ]);
        Expect::that($inner->events)
            ->because('the tap MUST forward every event unchanged')
            ->toBe($events);
        Expect::that($tap->retriedPasses())
            ->because('the tap MUST count only passed tests that used retry')
            ->toBe(1);
    }

    #[Test]
    public function incompleteAndMalformedClassLifecyclesDoNotInventDurations(): void
    {
        $tap = new FailedTestsTap(new CollectingEventSink());
        $events = [
            new TestClassStarted('App\CrashTest', 1.0, 'worker-1'),
            $this->finished('App\CrashTest', 'crashes', Outcome::Errored, 100.0),
            new TestClassFinished('App\MissingStartTest', 4.0, 'worker-2'),
            new TestClassStarted('App\ReversedTest', 10.0, 'worker-3'),
            new TestClassFinished('App\ReversedTest', 9.0, 'worker-3'),
            new TestClassStarted('App\CrashTest', 20.0, 'worker-4'),
            new TestClassFinished('App\CrashTest', 24.0, 'worker-4'),
            new TestClassFinished('App\CrashTest', 30.0, 'worker-4'),
            new TestClassStarted('App\RestartedTest', 40.0, 'worker-5'),
            new TestClassStarted('App\RestartedTest', 42.0, 'worker-5'),
            new TestClassFinished('App\RestartedTest', 45.0, 'worker-5'),
        ];

        foreach ($events as $event) {
            $tap->emit($event);
        }

        Expect::that($tap->classSeconds())
            ->because('only valid start and finish pairs MUST supply advisory durations')
            ->toBe([
                'App\CrashTest' => 4.0,
                'App\RestartedTest' => 3.0,
            ]);
        Expect::that($tap->failedTests())
            ->because('an incomplete class MUST still supply its failed test ID')
            ->toBe(['App\CrashTest::crashes']);
    }

    #[Test]
    public function accumulatedDurationsRemainPersistableAtTheFloatLimit(): void
    {
        $tap = new FailedTestsTap(new CollectingEventSink());
        $tap->emit(new TestClassStarted('App\ExtremeTest', 0.0, 'worker-1'));
        $tap->emit($this->finished('App\ExtremeTest', 'first', Outcome::Failed, 0.0));
        $tap->emit(new TestClassFinished('App\ExtremeTest', \PHP_FLOAT_MAX, 'worker-1'));
        $tap->emit(new TestClassStarted('App\ExtremeTest', 0.0, 'worker-2'));
        $tap->emit($this->finished('App\ExtremeTest', 'second', Outcome::Failed, 0.0));
        $tap->emit(new TestClassFinished('App\ExtremeTest', \PHP_FLOAT_MAX, 'worker-2'));

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
        int $attempts = 1,
    ): TestFinished {
        return new TestFinished(
            new TestResult(new TestId($class, $method), $outcome, $duration, 0, attempts: $attempts),
            1.0,
        );
    }
}
