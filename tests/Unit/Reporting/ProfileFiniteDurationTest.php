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

final readonly class ProfileFiniteDurationTest
{
    #[Test]
    public function extremeFiniteTimestampsKeepEveryProfileMetricFinite(): void
    {
        $aggregator = new ProfileAggregator();
        $events = [
            new RunStarted('run-1', 3, 3, 0.0),
            new WorkerSpawned('w-1', 11, -\PHP_FLOAT_MAX),
            new WorkerSpawned('w-2', 12, -\PHP_FLOAT_MAX),
            new WorkerSpawned('w-3', 13, -\PHP_FLOAT_MAX),
            new TestClassStarted('Acme\ExtremeTest', 0.0, 'w-1'),
            new TestClassStarted('Acme\ExtremeTest', 0.0, 'w-2'),
            new TestClassStarted('Acme\ExtremeTest', -\PHP_FLOAT_MAX, 'w-3'),
            new TestClassFinished('Acme\ExtremeTest', -\PHP_FLOAT_MAX, 'w-3'),
            new TestClassFinished('Acme\ExtremeTest', \PHP_FLOAT_MAX, 'w-1'),
            new TestClassFinished('Acme\ExtremeTest', \PHP_FLOAT_MAX, 'w-2'),
            new RunFinished('run-1', new ResultSummary(passed: 3), \PHP_FLOAT_MAX, \PHP_FLOAT_MAX),
        ];

        foreach ($events as $event) {
            $aggregator->onEvent($event);
        }

        Expect::that($aggregator->render(new Style(ansi: false)))
            ->because('finite event timestamps MUST produce only finite profile metrics')
            ->not()
            ->toContain('INF')
            ->not()
            ->toContain('NAN');
    }
}
