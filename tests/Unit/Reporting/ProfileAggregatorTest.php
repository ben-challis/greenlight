<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\ProfileAggregator;
use Greenlight\Reporting\Style;

final class ProfileAggregatorTest
{
    #[Test]
    public function derivesUtilizationBootLatencyAndSpreadFromACannedStream(): void
    {
        $aggregator = new ProfileAggregator();

        foreach ($this->cannedEvents() as $event) {
            $aggregator->onEvent($event);
        }

        $expected = <<<'TEXT'

            Profile:
              Workers: 2 requested, 2 spawned, 1 recycled
              Boot latency: 0.750s average (spawn to first class, 2 workers)

              Worker  Classes    Busy  Util
              w-1           2  3.500s   78%
              w-2           1  1.000s   50%
              Makespan spread: 2.500s between first and last worker finish

              Slowest classes:
                2.500s  Acme\AlphaTest
                1.000s  Acme\GammaTest
                1.000s  Acme\BetaTest

            TEXT;

        Expect::that($aggregator->render(new Style(ansi: false)))->because('derives utilization boot latency and spread from a canned stream')->toBe($expected);
    }

    #[Test]
    public function slowestDurationsRightAlignAcrossWidths(): void
    {
        $aggregator = new ProfileAggregator();

        $events = [
            new RunStarted('run-1', 2, 1, 100.0),
            new WorkerSpawned('w-1', 11, 100.0),
            new TestClassStarted('Acme\SlowTest', 100.0, 'w-1'),
            new TestClassFinished('Acme\SlowTest', 112.0, 'w-1'),
            new TestClassStarted('Acme\QuickTest', 112.0, 'w-1'),
            new TestClassFinished('Acme\QuickTest', 113.0, 'w-1'),
            new RunFinished('run-1', new ResultSummary(passed: 2), 13.0, 113.0),
        ];

        foreach ($events as $event) {
            $aggregator->onEvent($event);
        }

        $rendered = $aggregator->render(new Style(ansi: false));

        Expect::that($rendered)->because('slowest durations right align across widths')->toContain("    12.000s  Acme\\SlowTest\n")
            ->toContain("     1.000s  Acme\\QuickTest\n");
    }

    #[Test]
    public function utilizationBandsAndSlowDurationsColorWithAnsi(): void
    {
        $aggregator = new ProfileAggregator();

        foreach ($this->cannedEvents() as $event) {
            $aggregator->onEvent($event);
        }

        $rendered = $aggregator->render(new Style(ansi: true));

        // A value of 78% is in the middle band (yellow), and 50% is in the low
        // band (red). The 2.5-second class exceeds the slow limit (yellow).
        // Spaces outside color codes keep column alignment unchanged when the
        // output contains escape sequences.
        Expect::that($rendered)->because('utilization bands and slow durations color with ANSI')->toContain("3.500s   \x1b[33m78%\x1b[0m\n")
            ->toContain("1.000s   \x1b[31m50%\x1b[0m\n")
            ->toContain("\x1b[33m2.500s\x1b[0m  Acme\AlphaTest");
    }

    #[Test]
    public function fullyBusyWorkersColorGreen(): void
    {
        $aggregator = new ProfileAggregator();

        $events = [
            new RunStarted('run-1', 1, 1, 100.0),
            new WorkerSpawned('w-1', 11, 100.0),
            new TestClassStarted('Acme\AlphaTest', 100.0, 'w-1'),
            new TestClassFinished('Acme\AlphaTest', 100.5, 'w-1'),
            new RunFinished('run-1', new ResultSummary(passed: 1), 0.5, 100.5),
        ];

        foreach ($events as $event) {
            $aggregator->onEvent($event);
        }

        Expect::that($aggregator->render(new Style(ansi: true)))->because('fully busy workers color green')
            ->toContain("0.500s  \x1b[32m100%\x1b[0m\n");
    }

    #[Test]
    public function withoutAFinishedRunNothingRenders(): void
    {
        $aggregator = new ProfileAggregator();
        $aggregator->onEvent(new WorkerSpawned('w-1', 11, 100.0));

        Expect::that($aggregator->render(new Style(ansi: false)))->because('without a finished run nothing renders')->toBe('');
    }

    #[Test]
    public function spawnedWorkersWithoutClassesStayOutOfTheStatisticsTable(): void
    {
        $aggregator = new ProfileAggregator();
        $events = [
            new RunStarted('run-1', 1, 2, 100.0),
            new WorkerSpawned('active', 11, 100.0),
            new WorkerSpawned('idle', 12, 100.0),
            new TestClassStarted('Acme\ExampleTest', 100.5, 'active'),
            new TestClassFinished('Acme\ExampleTest', 101.0, 'active'),
            new RunFinished('run-1', new ResultSummary(passed: 1), 1.0, 101.0),
        ];

        foreach ($events as $event) {
            $aggregator->onEvent($event);
        }

        Expect::that($aggregator->render(new Style(ansi: false)))
            ->because('the summary counts every spawned worker')
            ->toContain('Workers: 2 requested, 2 spawned, 0 recycled')
            ->and($aggregator->render(new Style(ansi: false)))
            ->because('an idle worker has no class statistics to report')
            ->toContain("\n  active        1  0.500s")
            ->not()
            ->toContain("\n  idle");
    }

    /**
     * Worker w-1 starts in 0.5 seconds and runs two classes with a gap. It is
     * active for 3.5 seconds of a 4.5-second period. Worker w-2 starts in 1.0
     * second and is active for 1 second of a 2-second period. It finishes 2
     * seconds before w-1.
     *
     * @return list<Event>
     */
    private function cannedEvents(): array
    {
        return [
            new RunStarted('run-1', 10, 2, 100.0),
            new WorkerSpawned('w-1', 11, 100.0),
            new WorkerSpawned('w-2', 12, 100.0),
            new TestClassStarted('Acme\AlphaTest', 100.5, 'w-1'),
            new TestClassStarted('Acme\GammaTest', 101.0, 'w-2'),
            new TestClassFinished('Acme\GammaTest', 102.0, 'w-2'),
            new TestClassFinished('Acme\AlphaTest', 103.0, 'w-1'),
            new TestClassStarted('Acme\BetaTest', 103.5, 'w-1'),
            new WorkerRecycled('w-2', RecycleReason::TestCount, 103.5),
            new TestClassFinished('Acme\BetaTest', 104.5, 'w-1'),
            new RunFinished('run-1', new ResultSummary(passed: 10), 4.5, 104.5),
        ];
    }
}
