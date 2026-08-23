<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\PlainReporter;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

final readonly class PlainReporterFirstRetryTest
{
    #[Test]
    public function aFirstRetryIsReportedAsTwoAttempts(): void
    {
        $output = new BufferOutput();
        $reporter = new PlainReporter($output);
        $reporter->onEvent(new TestFinished(
            new TestResult(
                new TestId('Acme\\RetryTest', 'passesOnFirstRetry'),
                Outcome::Passed,
                0.01,
                0,
                attempts: 2,
            ),
            1_750_000_000.5,
        ));

        Expect::that($output->buffer())
            ->because('the first retry MUST report both attempts')
            ->toBe("PASS Acme\\RetryTest::passesOnFirstRetry (0.010s) (passed after 2 attempts)\n");
    }
}
