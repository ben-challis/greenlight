<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\ClassFailureTap;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\CollectingEventSink;

final class ClassFailureTapSkippedTest
{
    #[Test]
    public function skippedTestsDoNotSelectTheirClassForAFailedRerun(): void
    {
        $inner = new CollectingEventSink();
        $tap = new ClassFailureTap($inner);
        $event = new TestFinished(
            new TestResult(
                new TestId('App\SkippedTest', 'skips'),
                Outcome::Skipped,
                durationSeconds: 0.1,
                memoryDeltaBytes: 0,
            ),
            1.0,
        );

        $tap->emit($event);

        Expect::that($tap->failedClasses())
            ->because('a skipped test MUST NOT select its class for a failed watch rerun')
            ->toBe([]);
        Expect::that($inner->events)
            ->because('the skipped result MUST still reach the configured event sink')
            ->toBe([$event]);
    }
}
