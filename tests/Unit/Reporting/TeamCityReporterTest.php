<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Event\TestStarted;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\TeamCityReporter;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Sandbox\Autoloaders;
use Greenlight\Test\TestId;
use Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest;
use Greenlight\Tests\Support\ClassFile;

final readonly class TeamCityReporterTest
{
    public function __construct(private Autoloaders $autoloaders) {}

    #[Test]
    public function cannedStreamRendersTheGoldenServiceMessages(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new TeamCityReporter($output));

        $expected = <<<'TXT'
            ##teamcity[testSuiteStarted name='Acme\CalculatorTest' flowId='Acme\CalculatorTest']
            ##teamcity[testStarted name='Acme\CalculatorTest::addsIntegers' flowId='Acme\CalculatorTest']
            ##teamcity[testFinished name='Acme\CalculatorTest::addsIntegers' duration='12' flowId='Acme\CalculatorTest']
            ##teamcity[testStarted name='Acme\CalculatorTest::subtractsIntegers' flowId='Acme\CalculatorTest']
            ##teamcity[testFailed name='Acme\CalculatorTest::subtractsIntegers' message='Failed asserting that two values are equal.' details='at /project/tests/CalculatorTest.php:42' type='comparisonFailure' expected='2' actual='3' flowId='Acme\CalculatorTest']
            ##teamcity[testFinished name='Acme\CalculatorTest::subtractsIntegers' duration='20' flowId='Acme\CalculatorTest']
            ##teamcity[testStarted name='Acme\CalculatorTest::multipliesIntegers|[large numbers|]' flowId='Acme\CalculatorTest']
            ##teamcity[testFinished name='Acme\CalculatorTest::multipliesIntegers|[large numbers|]' duration='340' flowId='Acme\CalculatorTest']
            ##teamcity[testSuiteFinished name='Acme\CalculatorTest' flowId='Acme\CalculatorTest']
            ##teamcity[testSuiteStarted name='Acme\NetworkTest' flowId='Acme\NetworkTest']
            ##teamcity[testStarted name='Acme\NetworkTest::connects' flowId='Acme\NetworkTest']
            ##teamcity[testFailed name='Acme\NetworkTest::connects' message='RuntimeException: Connection refused.' details='Acme\NetworkTest::connect at /project/tests/NetworkTest.php:17|nat /project/tests/NetworkTest.php:17' flowId='Acme\NetworkTest']
            ##teamcity[testFinished name='Acme\NetworkTest::connects' duration='5' flowId='Acme\NetworkTest']
            ##teamcity[testStarted name='Acme\NetworkTest::pings' flowId='Acme\NetworkTest']
            ##teamcity[testIgnored name='Acme\NetworkTest::pings' message='Requires ext-redis.' flowId='Acme\NetworkTest']
            ##teamcity[testFinished name='Acme\NetworkTest::pings' duration='0' flowId='Acme\NetworkTest']
            ##teamcity[testStarted name='Acme\NetworkTest::retriesFlakyEndpoint' flowId='Acme\NetworkTest']
            ##teamcity[testMetadata testName='Acme\NetworkTest::retriesFlakyEndpoint' name='greenlight.attempts' type='number' value='3' flowId='Acme\NetworkTest']
            ##teamcity[testFinished name='Acme\NetworkTest::retriesFlakyEndpoint' duration='150' flowId='Acme\NetworkTest']
            ##teamcity[testSuiteFinished name='Acme\NetworkTest' flowId='Acme\NetworkTest']
            TXT;

        Expect::that($output->buffer())->because('canned stream renders the golden service messages')->toBe($expected . "\n");
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

        Expect::that($output->buffer())->because('values are escaped per service message rules')->toBe(
            "##teamcity[testFailed name='Acme\EscapeTest::escapes' message='pipe || quote |' bracket |[x|]|nnext' flowId='Acme\EscapeTest']\n"
            . "##teamcity[testFinished name='Acme\EscapeTest::escapes' duration='1' flowId='Acme\EscapeTest']\n",
        );
    }

    #[Test]
    public function anUnrepresentableDurationSaturatesAtTheIntegerMaximum(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);
        $id = new TestId('Acme\DurationTest', 'reports');

        $reporter->onEvent(new TestFinished(new TestResult(
            $id,
            Outcome::Passed,
            \PHP_FLOAT_MAX,
            0,
        ), 1.0));

        Expect::that($output->buffer())
            ->because('an unrepresentable TeamCity duration MUST NOT collapse to zero')
            ->toBe(\sprintf(
                "##teamcity[testFinished name='Acme\\DurationTest::reports' duration='%d' flowId='Acme\\DurationTest']\n",
                \PHP_INT_MAX,
            ));
    }

    #[Test]
    public function zeroStringFailureMessagesRemainDistinctFromMissingDetails(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);
        $result = new TestResult(
            new TestId('Acme\FailureTest', 'fails'),
            Outcome::Failed,
            0.001,
            0,
            failures: [new FailureDetail('0')],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));

        Expect::that($output->buffer())
            ->because('a zero-string failure message MUST remain distinct from missing failure details')
            ->toBe(
                "##teamcity[testFailed name='Acme\FailureTest::fails' message='0' flowId='Acme\FailureTest']\n"
                . "##teamcity[testFinished name='Acme\FailureTest::fails' duration='1' flowId='Acme\FailureTest']\n",
            );
    }

    #[Test]
    public function incompleteAndMultipleFailureDetailsRemainReportable(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);
        $class = 'Acme\PartialResultTest';
        $multiple = new TestId($class, 'multipleFailures');
        $incomplete = new TestId($class, 'missingErrorDetail');

        $reporter->onEvent(new TestFinished(new TestResult(
            $multiple,
            Outcome::Failed,
            0.001,
            0,
            failures: [
                new FailureDetail('primary failure'),
                new FailureDetail('secondary failure'),
            ],
        ), 1.0));
        $reporter->onEvent(new TestFinished(new TestResult(
            $incomplete,
            Outcome::Errored,
            0.002,
            0,
        ), 1.1));

        Expect::that($output->buffer())
            ->because('incomplete and multiple failure details remain reportable')
            ->toBe(
                "##teamcity[testFailed name='{$class}::multipleFailures' message='primary failure' details='secondary failure' flowId='{$class}']\n"
                . "##teamcity[testFinished name='{$class}::multipleFailures' duration='1' flowId='{$class}']\n"
                . "##teamcity[testFailed name='{$class}::missingErrorDetail' message='errored' flowId='{$class}']\n"
                . "##teamcity[testFinished name='{$class}::missingErrorDetail' duration='2' flowId='{$class}']\n",
            );
    }

    #[Test]
    public function loadableClassesGetPhpQnLocationHints(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);

        $class = AlphaTest::class;
        $file = ClassFile::of($class);

        $reporter->onEvent(new TestClassStarted($class, 1.0, 'w-1'));
        $reporter->onEvent(new TestStarted(new TestId($class, 'one'), 1.1));
        $reporter->onEvent(new TestStarted(new TestId($class, 'two', 'large input'), 1.2));

        Expect::that($output->buffer())->because('loadable classes get php_qn location hints')->toBe(
            "##teamcity[testSuiteStarted name='{$class}' locationHint='php_qn://{$file}::\\{$class}' flowId='{$class}']\n"
            . "##teamcity[testStarted name='{$class}::one' locationHint='php_qn://{$file}::\\{$class}::one' flowId='{$class}']\n"
            . "##teamcity[testStarted name='{$class}::two|[large input|]' locationHint='php_qn://{$file}::\\{$class}::two' flowId='{$class}']\n",
        );
    }

    #[Test]
    public function unloadableClassesOmitTheLocationHint(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);

        $class = 'Acme\Vanished\GhostTest';

        $reporter->onEvent(new TestClassStarted($class, 1.0, 'w-1'));
        $reporter->onEvent(new TestStarted(new TestId($class, 'haunts'), 1.1));

        Expect::that($output->buffer())->because('unloadable classes omit the location hint')->toBe(
            "##teamcity[testSuiteStarted name='{$class}' flowId='{$class}']\n"
            . "##teamcity[testStarted name='{$class}::haunts' flowId='{$class}']\n",
        );
    }

    #[Test]
    public function autoloaderErrorsDoNotStopTheReportStream(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);
        $class = 'Acme\BrokenAutoloaderTest';
        $autoload = static function (string $requested) use ($class): void {
            if ($requested === $class) {
                throw new \RuntimeException('autoload failed');
            }
        };
        $this->autoloaders->register($autoload);

        $reporter->onEvent(new TestClassStarted($class, 1.0, 'w-1'));

        Expect::that($output->buffer())
            ->because('an autoloader error omits the optional navigation hint')
            ->toBe("##teamcity[testSuiteStarted name='{$class}' flowId='{$class}']\n");
    }

    #[Test]
    public function interleavedClassesKeepDistinctFlows(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);

        $alpha = 'Acme\Flow\AlphaTest';
        $beta = 'Acme\Flow\BetaTest';
        $alphaTest = new TestId($alpha, 'first');
        $betaTest = new TestId($beta, 'first');

        $reporter->onEvent(new TestClassStarted($alpha, 1.0, 'w-1'));
        $reporter->onEvent(new TestClassStarted($beta, 1.01, 'w-2'));
        $reporter->onEvent(new TestStarted($alphaTest, 1.02));
        $reporter->onEvent(new TestStarted($betaTest, 1.03));
        $reporter->onEvent(new TestFinished(new TestResult($betaTest, Outcome::Passed, 0.005, 0), 1.04));
        $reporter->onEvent(new TestFinished(new TestResult($alphaTest, Outcome::Passed, 0.007, 0), 1.05));
        $reporter->onEvent(new TestClassFinished($beta, 1.06, 'w-2'));
        $reporter->onEvent(new TestClassFinished($alpha, 1.07, 'w-1'));
        $reporter->finish();

        Expect::that($output->buffer())->because('interleaved classes keep distinct flows')->toBe(
            "##teamcity[testSuiteStarted name='{$alpha}' flowId='{$alpha}']\n"
            . "##teamcity[testSuiteStarted name='{$beta}' flowId='{$beta}']\n"
            . "##teamcity[testStarted name='{$alpha}::first' flowId='{$alpha}']\n"
            . "##teamcity[testStarted name='{$beta}::first' flowId='{$beta}']\n"
            . "##teamcity[testFinished name='{$beta}::first' duration='5' flowId='{$beta}']\n"
            . "##teamcity[testFinished name='{$alpha}::first' duration='7' flowId='{$alpha}']\n"
            . "##teamcity[testSuiteFinished name='{$beta}' flowId='{$beta}']\n"
            . "##teamcity[testSuiteFinished name='{$alpha}' flowId='{$alpha}']\n",
        );
    }

    #[Test]
    public function concurrentAssignmentsForOneClassShareOneSuite(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);
        $class = 'Acme\Flow\SplitTest';

        $reporter->onEvent(new TestClassStarted($class, 1.0, 'w-1'));
        $reporter->onEvent(new TestClassStarted($class, 1.01, 'w-2'));
        $reporter->onEvent(new TestClassFinished($class, 1.02, 'w-1'));
        $reporter->onEvent(new TestClassFinished($class, 1.03, 'w-2'));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('concurrent split assignments MUST share one TeamCity suite flow')
            ->toBe(
                "##teamcity[testSuiteStarted name='{$class}' flowId='{$class}']\n"
                . "##teamcity[testSuiteFinished name='{$class}' flowId='{$class}']\n",
            );
    }
}
