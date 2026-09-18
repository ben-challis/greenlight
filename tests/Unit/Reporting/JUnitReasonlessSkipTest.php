<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\TestFinished;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

use function Greenlight\expect;

final class JUnitReasonlessSkipTest
{
    #[Test]
    public function skippedResultsWithoutAReasonDoNotInventAMessage(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $reporter->onEvent(new TestFinished(
            new TestResult(
                new TestId('App\ExampleTest', 'example'),
                Outcome::Skipped,
                0.0,
                0,
            ),
            1.0,
        ));

        $reporter->finish();

        expect($output->buffer())
            ->because('a reasonless skip remains a valid JUnit skipped element without invented detail')
            ->toContain('<skipped/>')
            ->not()
            ->toContain('<skipped message=');
    }
}
