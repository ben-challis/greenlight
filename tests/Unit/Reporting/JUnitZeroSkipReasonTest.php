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

final class JUnitZeroSkipReasonTest
{
    #[Test]
    public function zeroSkipReasonRemainsInTheReport(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $result = new TestResult(
            new TestId('Acme\\ConditionalTest', 'skips'),
            Outcome::Skipped,
            0.0,
            0,
            skipReason: '0',
        );

        $reporter->onEvent(new TestFinished($result, 1.0));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('JUnit MUST preserve the skip reason "0"')
            ->toContain('<skipped message="0">0</skipped>');
    }
}
