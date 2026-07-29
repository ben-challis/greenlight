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

final readonly class ProfileAggregatorZeroUtilizationTest
{
    #[Test]
    public function measurableIdleTimeRendersZeroUtilization(): void
    {
        $aggregator = new ProfileAggregator();

        foreach ([
            new RunStarted('run-1', 1, 1, 100.0),
            new WorkerSpawned('w-1', 11, 100.0),
            new TestClassStarted('Acme\InstantTest', 101.0, 'w-1'),
            new TestClassFinished('Acme\InstantTest', 101.0, 'w-1'),
            new RunFinished('run-1', new ResultSummary(passed: 1), 1.0, 101.0),
        ] as $event) {
            $aggregator->onEvent($event);
        }

        Expect::that($aggregator->render(new Style(ansi: false)))
            ->because('measured idle time MUST render zero utilization')
            ->toContain("\n  w-1           1  0.000s    0%\n");
    }
}
