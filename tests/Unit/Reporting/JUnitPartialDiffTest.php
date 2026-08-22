<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

final class JUnitPartialDiffTest
{
    #[Test]
    public function partialFailureDiffsRemainInTheirFailureElements(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $result = new TestResult(
            new TestId('Acme\PartialDiffTest', 'reports'),
            Outcome::Failed,
            0.001,
            0,
            failures: [
                new FailureDetail('expected only', expected: ''),
                new FailureDetail('actual only', actual: ''),
            ],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));
        $reporter->finish();

        $expected = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites name="greenlight" tests="1" failures="1" errors="0" skipped="0" time="0.001000">
              <testsuite name="Acme\PartialDiffTest" tests="1" failures="1" errors="0" skipped="0" assertions="0" time="0.001000">
                <testcase name="reports" classname="Acme\PartialDiffTest" assertions="0" time="0.001000">
                  <failure type="failure" message="expected only">expected: </failure>
                  <failure type="failure" message="actual only">actual: </failure>
                </testcase>
              </testsuite>
            </testsuites>
            XML;

        Expect::that($output->buffer())
            ->because('JUnit failure elements MUST retain each available partial diff side')
            ->toBe($expected . "\n");
    }
}
