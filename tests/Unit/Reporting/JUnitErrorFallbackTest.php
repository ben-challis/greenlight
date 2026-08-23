<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

final class JUnitErrorFallbackTest
{
    #[Test]
    public function erroredOutcomeWithoutDetailsWritesAnErrorElement(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $reporter->onEvent(new TestFinished(new TestResult(
            new TestId('Acme\PartialResultTest', 'missingErrorDetail'),
            Outcome::Errored,
            0.002,
            0,
        ), 1.0));

        $reporter->finish();

        Expect::that($output->buffer())
            ->because('the error count and testcase element MUST remain consistent without details')
            ->toBe(
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<testsuites name=\"greenlight\" tests=\"1\" failures=\"0\" errors=\"1\" skipped=\"0\" time=\"0.002000\">\n"
                . "  <testsuite name=\"Acme\\PartialResultTest\" tests=\"1\" failures=\"0\" errors=\"1\" skipped=\"0\" assertions=\"0\" time=\"0.002000\">\n"
                . "    <testcase name=\"missingErrorDetail\" classname=\"Acme\\PartialResultTest\" assertions=\"0\" time=\"0.002000\">\n"
                . "      <error type=\"error\" message=\"errored\">errored</error>\n"
                . "    </testcase>\n"
                . "  </testsuite>\n"
                . "</testsuites>\n",
            );
    }
}
