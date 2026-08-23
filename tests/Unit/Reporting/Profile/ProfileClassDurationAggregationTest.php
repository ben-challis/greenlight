<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting\Profile;

use Greenlight\Attribute\Test;
use Greenlight\Event\RunFinished;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Event\WorkerSpawned;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Profile\ProfileAggregator;
use Greenlight\Reporting\Style;
use Greenlight\Result\ResultSummary;

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
