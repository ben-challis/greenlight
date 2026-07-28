<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\ProfileAggregator;
use Greenlight\Reporting\Style;

final class ProfileClassDurationAggregationTest
{
    #[Test]
    public function repeatedClassSpansContributeToOneTotalDuration(): void
    {
        $aggregator = new ProfileAggregator();
        $events = [
            new RunStarted('run-1', 2, 1, 100.0),
            new WorkerSpawned('w-1', 1, 100.0),
            new TestClassStarted('Acme\RepeatedTest', 100.0, 'w-1'),
            new TestClassFinished('Acme\RepeatedTest', 101.25, 'w-1'),
            new TestClassStarted('Acme\RepeatedTest', 102.0, 'w-1'),
            new TestClassFinished('Acme\RepeatedTest', 104.5, 'w-1'),
            new RunFinished('run-1', new ResultSummary(passed: 2), 4.5, 104.5),
        ];

        foreach ($events as $event) {
            $aggregator->onEvent($event);
        }

        $slowest = \strstr(
            $aggregator->render(new Style(ansi: false)),
            "\n  Slowest classes:",
        );

        Expect::that($slowest)
            ->because('each completed span MUST contribute to the class profile total')
            ->toBe(
                "\n  Slowest classes:\n"
                . "    3.750s  Acme\\RepeatedTest\n",
            );
    }
}
