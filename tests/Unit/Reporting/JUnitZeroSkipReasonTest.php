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
            ->toContain('<skipped message="0"/>');
    }
}
