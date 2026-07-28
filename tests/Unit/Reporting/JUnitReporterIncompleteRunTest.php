<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JUnitReporter;

final class JUnitReporterIncompleteRunTest
{
    #[Test]
    public function missingRunFinishedUsesTheSummedTestDuration(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $reporter->onEvent(new TestFinished(
            new TestResult(
                new TestId('Acme\InterruptedTest', 'first'),
                Outcome::Passed,
                0.25,
                1,
            ),
            1.0,
        ));
        $reporter->onEvent(new TestFinished(
            new TestResult(
                new TestId('Acme\InterruptedTest', 'second'),
                Outcome::Passed,
                0.5,
                1,
            ),
            2.0,
        ));

        $reporter->finish();

        Expect::that($output->buffer())
            ->because('incomplete JUnit streams MUST report the summed test duration')
            ->toContain(
                '<testsuites name="greenlight" tests="2" failures="0" errors="0" '
                . 'skipped="0" time="0.750000">',
            );
    }
}
