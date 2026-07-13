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
    public function derivesUtilisationBootLatencyAndSpreadFromACannedStream(): void
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

        Expect::that($aggregator->render(new Style(ansi: false)))->toBe($expected);
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

        Expect::that($rendered)->toContain("    12.000s  Acme\\SlowTest\n")
            ->and($rendered)->toContain("     1.000s  Acme\\QuickTest\n");
    }

    #[Test]
    public function utilisationBandsAndSlowDurationsColourWithAnsi(): void
    {
        $aggregator = new ProfileAggregator();

        foreach ($this->cannedEvents() as $event) {
            $aggregator->onEvent($event);
        }

        $rendered = $aggregator->render(new Style(ansi: true));

        // 78% sits in the mid band (yellow), 50% below it (red); the 2.5s
        // class crosses the slow threshold (yellow). Cells pad outside the
        // colour codes so escape sequences cannot break column alignment.
        Expect::that($rendered)->toContain("3.500s   \x1b[33m78%\x1b[0m\n")
            ->and($rendered)->toContain("1.000s   \x1b[31m50%\x1b[0m\n")
            ->and($rendered)->toContain("\x1b[33m2.500s\x1b[0m  Acme\AlphaTest");
    }

    #[Test]
    public function fullyBusyWorkersColourGreen(): void
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

        Expect::that($aggregator->render(new Style(ansi: true)))
            ->toContain("0.500s  \x1b[32m100%\x1b[0m\n");
    }

    #[Test]
    public function withoutAFinishedRunNothingRenders(): void
    {
        $aggregator = new ProfileAggregator();
        $aggregator->onEvent(new WorkerSpawned('w-1', 11, 100.0));

        Expect::that($aggregator->render(new Style(ansi: false)))->toBe('');
    }

    /**
     * Worker w-1 boots in 0.5s, runs two classes with a gap (busy 3.5s of a
     * 4.5s window). Worker w-2 boots in 1.0s, runs one class (busy 1s of a
     * 2s window) and finishes 2 seconds before w-1.
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
