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

final class TeamCityReporterCarriageReturnTest
{
    #[Test]
    public function carriageReturnsUseTheTeamCityServiceMessageEscape(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);
        $result = new TestResult(
            new TestId('Acme\EscapeTest', 'carriageReturn'),
            Outcome::Failed,
            0.001,
            0,
            failures: [new FailureDetail("before\rafter")],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));

        Expect::that($output->buffer())
            ->because('TeamCity service messages MUST escape carriage returns as |r')
            ->toContain("message='before|rafter'");
    }
}
