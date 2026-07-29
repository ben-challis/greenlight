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
            ->toBe("PASS Acme\\RetryTest::passesOnFirstRetry (0.010s) (attempts: 2)\n");
    }
}
