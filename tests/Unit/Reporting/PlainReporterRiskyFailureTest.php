<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\PlainReporter;

final class PlainReporterRiskyFailureTest
{
    #[Test]
    public function failedRiskyTestsDoNotReceivePassedTestGuidance(): void
    {
        $output = new BufferOutput();
        $reporter = new PlainReporter($output);
        $reporter->onEvent(new TestFinished(
            new TestResult(
                new TestId('Acme\\RiskyTest', 'failsWithoutExpectations'),
                Outcome::Failed,
                0.01,
                0,
                risky: true,
            ),
            1_750_000_000.5,
        ));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('a failed risky test MUST keep its failure without passed-test guidance')
            ->toContain('FAIL Acme\RiskyTest::failsWithoutExpectations')
            ->not()
            ->toContain('These tests passed without a verified expectation.');
    }
}
