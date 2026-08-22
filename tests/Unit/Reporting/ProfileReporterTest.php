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
use Greenlight\Reporting\ProfileReporter;

final class ProfileReporterTest
{
    #[Test]
    public function profileOutputIsBufferedUntilTheRunStreamFinishes(): void
    {
        $output = new BufferOutput();
        $reporter = new ProfileReporter($output);
        $events = [
            new RunStarted('run-1', 1, 1, 100.0),
            new WorkerSpawned('worker-1', 42, 100.0),
            new TestClassStarted('Acme\ProfiledTest', 100.1, 'worker-1'),
            new TestClassFinished('Acme\ProfiledTest', 100.6, 'worker-1'),
            new RunFinished('run-1', new ResultSummary(passed: 1), 0.6, 100.6),
        ];

        foreach ($events as $event) {
            $reporter->onEvent($event);
        }

        Expect::that($output->buffer())
            ->because('profile events MUST remain buffered until reporter completion')
            ->toBe('');

        $reporter->finish();

        Expect::that($output->buffer())
            ->because('reporter completion MUST write the aggregated run profile')
            ->toBe(
                "\nProfile:\n"
                . "  Workers: 1 requested, 1 spawned\n"
                . "  Boot latency: 0.100s average (spawn to first class, 1 worker)\n"
                . "\n"
                . "  Worker    Classes    Busy  Util\n"
                . "  worker-1        1  0.500s   83%\n"
                . "\n"
                . "  Slowest classes:\n"
                . "    0.500s  Acme\\ProfiledTest\n",
            );
    }
}
