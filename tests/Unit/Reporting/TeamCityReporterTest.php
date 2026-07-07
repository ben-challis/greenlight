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

final class TeamCityReporterTest
{
    #[Test]
    public function cannedStreamRendersTheGoldenServiceMessages(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new TeamCityReporter($output));

        $expected = <<<'TXT'
            ##teamcity[testSuiteStarted name='Acme\CalculatorTest']
            ##teamcity[testStarted name='Acme\CalculatorTest::addsIntegers']
            ##teamcity[testFinished name='Acme\CalculatorTest::addsIntegers' duration='12']
            ##teamcity[testStarted name='Acme\CalculatorTest::subtractsIntegers']
            ##teamcity[testFailed name='Acme\CalculatorTest::subtractsIntegers' message='Failed asserting that two values are equal.' details='at /project/tests/CalculatorTest.php:42' type='comparisonFailure' expected='2' actual='3']
            ##teamcity[testFinished name='Acme\CalculatorTest::subtractsIntegers' duration='20']
            ##teamcity[testStarted name='Acme\CalculatorTest::multipliesIntegers|[large numbers|]']
            ##teamcity[testFinished name='Acme\CalculatorTest::multipliesIntegers|[large numbers|]' duration='340']
            ##teamcity[testSuiteFinished name='Acme\CalculatorTest']
            ##teamcity[testSuiteStarted name='Acme\NetworkTest']
            ##teamcity[testStarted name='Acme\NetworkTest::connects']
            ##teamcity[testFailed name='Acme\NetworkTest::connects' message='RuntimeException: Connection refused.' details='Acme\NetworkTest::connect at /project/tests/NetworkTest.php:17|nat /project/tests/NetworkTest.php:17']
            ##teamcity[testFinished name='Acme\NetworkTest::connects' duration='5']
            ##teamcity[testStarted name='Acme\NetworkTest::pings']
            ##teamcity[testIgnored name='Acme\NetworkTest::pings' message='Requires ext-redis.']
            ##teamcity[testFinished name='Acme\NetworkTest::pings' duration='0']
            ##teamcity[testStarted name='Acme\NetworkTest::retriesFlakyEndpoint']
            ##teamcity[testFinished name='Acme\NetworkTest::retriesFlakyEndpoint' duration='150']
            ##teamcity[testSuiteFinished name='Acme\NetworkTest']
            TXT;

        new Expect()->that($output->buffer())->toBe($expected . "\n");
    }

    #[Test]
    public function valuesAreEscapedPerServiceMessageRules(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);

        $result = new TestResult(
            new TestId('Acme\EscapeTest', 'escapes'),
            Outcome::Failed,
            0.001,
            0,
            failures: [new FailureDetail("pipe | quote ' bracket [x]\nnext")],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));
        $reporter->finish();

        new Expect()->that($output->buffer())->toBe(
            "##teamcity[testFailed name='Acme\EscapeTest::escapes' message='pipe || quote |' bracket |[x|]|nnext']\n"
            . "##teamcity[testFinished name='Acme\EscapeTest::escapes' duration='1']\n",
        );
    }
}
