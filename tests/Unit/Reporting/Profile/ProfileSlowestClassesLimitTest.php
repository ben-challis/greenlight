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

final class ProfileSlowestClassesLimitTest
{
    #[Test]
    public function keepsTheTenSlowestClassesInDescendingOrder(): void
    {
        $aggregator = new ProfileAggregator();
        $aggregator->onEvent(new RunStarted('run-1', 12, 1, 100.0));
        $aggregator->onEvent(new WorkerSpawned('w-1', 1, 100.0));
        $time = 100.0;

        for ($case = 1; $case <= 12; ++$case) {
            $class = \sprintf('Acme\\Case%02dTest', $case);
            $aggregator->onEvent(new TestClassStarted($class, $time, 'w-1'));
            $time += $case;
            $aggregator->onEvent(new TestClassFinished($class, $time, 'w-1'));
        }

        $aggregator->onEvent(new RunFinished(
            'run-1',
            new ResultSummary(passed: 12),
            $time - 100.0,
            $time,
        ));
        $slowest = \strstr(
            $aggregator->render(new Style(ansi: false)),
            "\n  Slowest classes:",
        );

        Expect::that($slowest)
            ->because('the profile MUST list exactly the ten slowest classes in descending order')
            ->toBe(
                "\n  Slowest classes:\n"
                . "    12.000s  Acme\\Case12Test\n"
                . "    11.000s  Acme\\Case11Test\n"
                . "    10.000s  Acme\\Case10Test\n"
                . "     9.000s  Acme\\Case09Test\n"
                . "     8.000s  Acme\\Case08Test\n"
                . "     7.000s  Acme\\Case07Test\n"
                . "     6.000s  Acme\\Case06Test\n"
                . "     5.000s  Acme\\Case05Test\n"
                . "     4.000s  Acme\\Case04Test\n"
                . "     3.000s  Acme\\Case03Test\n",
            );
    }
}
