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

final readonly class ProfileZeroMakespanSpreadTest
{
    #[Test]
    public function simultaneousWorkerFinishesRetainAZeroMakespanSpread(): void
    {
        $aggregator = new ProfileAggregator();

        foreach ([
            new RunStarted('run-1', 2, 2, 100.0),
            new WorkerSpawned('w-1', 1, 100.0),
            new WorkerSpawned('w-2', 2, 100.0),
            new TestClassStarted('Acme\FirstTest', 100.0, 'w-1'),
            new TestClassStarted('Acme\SecondTest', 100.0, 'w-2'),
            new TestClassFinished('Acme\FirstTest', 101.0, 'w-1'),
            new TestClassFinished('Acme\SecondTest', 101.0, 'w-2'),
            new RunFinished('run-1', new ResultSummary(passed: 2), 1.0, 101.0),
        ] as $event) {
            $aggregator->onEvent($event);
        }

        Expect::that($aggregator->render(new Style(ansi: false)))
            ->because('zero makespan spread is measured and MUST NOT be treated as missing')
            ->toContain(
                'Makespan spread: 0.000s between first and last worker finish',
            );
    }
}
