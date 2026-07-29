<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\TeamCityReporter;

final class TeamCityReasonlessSkipTest
{
    #[Test]
    public function reasonlessSkipsUseTheTeamCityFallbackMessage(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);
        $result = new TestResult(
            new TestId('Acme\NetworkTest', 'pings'),
            Outcome::Skipped,
            0.0,
            0,
        );

        $reporter->onEvent(new TestFinished($result, 1.0));

        Expect::that($output->buffer())
            ->because('a reasonless skip MUST retain the TeamCity fallback message')
            ->toBe(
                "##teamcity[testIgnored name='Acme\\NetworkTest::pings' message='skipped' flowId='Acme\\NetworkTest']\n"
                . "##teamcity[testFinished name='Acme\\NetworkTest::pings' duration='0' flowId='Acme\\NetworkTest']\n",
            );
    }

    #[Test]
    public function zeroStringSkipReasonsRemainDistinctFromNoReason(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);
        $result = new TestResult(
            new TestId('Acme\NetworkTest', 'pings'),
            Outcome::Skipped,
            0.0,
            0,
            skipReason: '0',
        );

        $reporter->onEvent(new TestFinished($result, 1.0));

        Expect::that($output->buffer())
            ->because('a zero-string skip reason MUST remain distinct from a missing reason')
            ->toBe(
                "##teamcity[testIgnored name='Acme\\NetworkTest::pings' message='0' flowId='Acme\\NetworkTest']\n"
                . "##teamcity[testFinished name='Acme\\NetworkTest::pings' duration='0' flowId='Acme\\NetworkTest']\n",
            );
    }
}
