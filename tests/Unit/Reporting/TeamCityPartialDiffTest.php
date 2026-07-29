<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\TeamCityReporter;

final class TeamCityPartialDiffTest
{
    #[Test]
    public function partialFailureDiffsRemainInTheDetails(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);
        $result = new TestResult(
            new TestId('Acme\PartialDiffTest', 'reports'),
            Outcome::Failed,
            0.001,
            0,
            failures: [
                new FailureDetail('primary failure', expected: 'left'),
                new FailureDetail('secondary failure', actual: 'right'),
            ],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));

        Expect::that($output->buffer())
            ->because('TeamCity details MUST retain each available partial diff side')
            ->toBe(
                "##teamcity[testFailed name='Acme\\PartialDiffTest::reports' message='primary failure'"
                . " details='expected: left|nsecondary failure|nactual: right'"
                . " flowId='Acme\\PartialDiffTest']\n"
                . "##teamcity[testFinished name='Acme\\PartialDiffTest::reports' duration='1'"
                . " flowId='Acme\\PartialDiffTest']\n",
            );
    }
}
