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

final readonly class ProfileZeroBootLatencyTest
{
    #[Test]
    public function zeroBootLatencyRemainsAMeasuredValue(): void
    {
        $aggregator = new ProfileAggregator();

        foreach ([
            new RunStarted('run-1', 1, 1, 100.0),
            new WorkerSpawned('w-1', 1, 100.0),
            new TestClassStarted('Acme\ExampleTest', 100.0, 'w-1'),
            new TestClassFinished('Acme\ExampleTest', 101.0, 'w-1'),
            new RunFinished('run-1', new ResultSummary(passed: 1), 1.0, 101.0),
        ] as $event) {
            $aggregator->onEvent($event);
        }

        Expect::that($aggregator->render(new Style(ansi: false)))
            ->because('zero boot latency is measured and MUST NOT be treated as missing')
            ->toContain(
                'Boot latency: 0.000s average (spawn to first class, 1 worker)',
            );
    }
}
