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

final class JUnitZeroDataSetKeyTest
{
    #[Test]
    public function zeroDataSetKeyRemainsInTheTestcaseName(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $result = new TestResult(
            new TestId('Acme\\DataSetTest', 'checksValue', '0'),
            Outcome::Passed,
            0.0,
            1,
        );

        $reporter->onEvent(new TestFinished($result, 1.0));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('JUnit MUST preserve the data-set key "0" in the testcase name')
            ->toContain('<testcase name="checksValue[0]"');
    }
}
