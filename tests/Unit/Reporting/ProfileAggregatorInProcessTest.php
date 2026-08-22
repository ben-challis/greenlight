<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\RunFinished;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\ProfileAggregator;
use Greenlight\Reporting\Style;
use Greenlight\Result\ResultSummary;

final class ProfileAggregatorInProcessTest
{
    #[Test]
    public function inProcessClassEventsDoNotCreateAWorkerProfile(): void
    {
        $aggregator = new ProfileAggregator();
        $events = [
            new RunStarted('run-1', 1, 1, 100.0),
            new TestClassStarted('Acme\ExampleTest', 100.0, ''),
            new TestClassFinished('Acme\ExampleTest', 100.5, ''),
            new RunFinished('run-1', new ResultSummary(passed: 1), 0.5, 100.5),
        ];

        foreach ($events as $event) {
            $aggregator->onEvent($event);
        }

        Expect::that($aggregator->render(new Style(ansi: false)))
            ->because('in-process class events MUST NOT invent a worker profile')
            ->toBe(
                "\nProfile:\n"
                . "  Workers: 1 requested, 0 spawned\n",
            );
    }
}
