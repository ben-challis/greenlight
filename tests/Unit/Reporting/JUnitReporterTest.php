<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\RunFinished;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\SourceLocation;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\SimpleXml;

final class JUnitReporterTest
{
    #[Test]
    public function cannedStreamRendersTheGoldenXml(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new JUnitReporter($output));

        $expected = <<<'TXT'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites name="greenlight" tests="6" failures="1" errors="1" skipped="1" time="1.234000">
              <testsuite name="Acme\CalculatorTest" tests="3" failures="1" errors="0" skipped="0" assertions="7" time="0.372000">
                <testcase name="addsIntegers" classname="Acme\CalculatorTest" assertions="2" time="0.012000"/>
                <testcase name="subtractsIntegers" classname="Acme\CalculatorTest" assertions="1" time="0.020000">
                  <failure type="failure" message="Failed asserting that two values are equal.">Failed asserting that two values are equal.
            expected: 2
            actual: 3
            at /project/tests/CalculatorTest.php:42</failure>
                </testcase>
                <testcase name="multipliesIntegers[large numbers]" classname="Acme\CalculatorTest" assertions="4" time="0.340000"/>
              </testsuite>
              <testsuite name="Acme\NetworkTest" tests="3" failures="0" errors="1" skipped="1" assertions="4" time="0.155000">
                <testcase name="connects" classname="Acme\NetworkTest" assertions="1" time="0.005000">
                  <error type="RuntimeException" message="Connection refused.">Connection refused.
            Acme\NetworkTest::connect at /project/tests/NetworkTest.php:17
            at /project/tests/NetworkTest.php:17</error>
                </testcase>
                <testcase name="pings" classname="Acme\NetworkTest" assertions="0" time="0.000000">
                  <skipped message="Requires ext-redis.">Requires ext-redis.</skipped>
                </testcase>
                <testcase name="retriesFlakyEndpoint" classname="Acme\NetworkTest" assertions="3" time="0.150000"/>
              </testsuite>
            </testsuites>
            TXT;

        Expect::that($output->buffer())->because('canned stream renders the golden XML')->toBe($expected . "\n");
    }

    #[Test]
    public function xmlParsesAndCountsMatchTheStream(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new JUnitReporter($output));

        $document = \simplexml_load_string($output->buffer());

        Expect::that($document)->because('XML parses and counts match the stream')->toBeInstanceOf(\SimpleXMLElement::class);

        Expect::that((string) $document['tests'])->because('XML parses and counts match the stream')->toBe('6');
        Expect::that((string) $document['failures'])->toBe('1');
        Expect::that((string) $document['errors'])->toBe('1');
        Expect::that((string) $document['skipped'])->toBe('1');
        Expect::that(SimpleXml::xpath($document, '//testcase'))->toHaveCount(6);
        Expect::that(SimpleXml::xpath($document, '//testsuite'))->toHaveCount(2);
        Expect::that(SimpleXml::xpath($document, '//failure'))->toHaveCount(1);
        Expect::that(SimpleXml::xpath($document, '//error'))->toHaveCount(1);
        Expect::that(SimpleXml::xpath($document, '//skipped'))->toHaveCount(1);
    }

    #[Test]
    public function multipleFailureDetailsRemainDistinct(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $result = new TestResult(
            new TestId('Acme\MultipleFailureTest', 'checksEveryValue'),
            Outcome::Failed,
            0.001,
            2,
            failures: [
                new FailureDetail('first failure'),
                new FailureDetail('second failure'),
            ],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));
        $reporter->finish();
        $document = \simplexml_load_string($output->buffer());

        Expect::that($document)
            ->because('multiple failure details produce valid JUnit XML')
            ->toBeInstanceOf(\SimpleXMLElement::class);

        $failures = SimpleXml::xpath($document, '//failure');

        Expect::that((string) $document['failures'])
            ->because('one failed test contributes one suite failure')
            ->toBe('1');
        Expect::that($failures)
            ->because('each failure detail remains visible to JUnit consumers')
            ->toHaveCount(2);

        Expect::that((string) $failures[0]['message'])
            ->because('failure details retain their encounter order')
            ->toBe('first failure');
        Expect::that((string) $failures[1]['message'])
            ->toBe('second failure');
        Expect::that((string) $failures[0])
            ->because('each failure element contains its primary diagnostic')
            ->toBe('first failure');
        Expect::that((string) $failures[1])
            ->toBe('second failure');
    }

    #[Test]
    public function loadableTestMethodsIncludeTheirSourceFile(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $reporter->onEvent(new TestFinished(new TestResult(
            new TestId(self::class, __FUNCTION__),
            Outcome::Passed,
            0.001,
            1,
        ), 1.0));

        $reporter->finish();
        $document = \simplexml_load_string($output->buffer());

        Expect::that($document)
            ->because('the source-file attribute MUST preserve valid JUnit XML')
            ->toBeInstanceOf(\SimpleXMLElement::class);

        $case = SimpleXml::xpath($document, '/testsuites/testsuite/testcase')[0];

        Expect::that((string) $case['file'])
            ->because('a loadable test method MUST identify its source file')
            ->toBe(__FILE__);
    }

    #[Test]
    public function unavailableSourceMetadataDoesNotStopReportGeneration(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $reporter->onEvent(new TestFinished(new TestResult(
            new TestId('Acme\\UnavailableSourceTest', 'runs'),
            Outcome::Passed,
            0.001,
            0,
        ), 1.0));
        $reporter->onEvent(new TestFinished(new TestResult(
            new TestId(self::class, 'unavailableMethod'),
            Outcome::Passed,
            0.001,
            0,
        ), 1.1));

        $reporter->finish();
        $document = \simplexml_load_string($output->buffer());

        Expect::that($document)
            ->because('unavailable source metadata MUST preserve valid JUnit XML')
            ->toBeInstanceOf(\SimpleXMLElement::class);

        $cases = SimpleXml::xpath($document, '/testsuites/testsuite/testcase');

        Expect::that(SimpleXml::attributes($cases[0]))
            ->because('an unavailable test class MUST omit the optional source file')
            ->not()
            ->toHaveKey('file');
        Expect::that(SimpleXml::attributes($cases[1]))
            ->because('an unavailable test method MUST omit the optional source file')
            ->not()
            ->toHaveKey('file');
    }

    #[Test]
    public function testDurationsProvideTheTotalWithoutARunFinishedEvent(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);

        foreach (CannedStream::events() as $event) {
            if (!$event instanceof RunFinished) {
                $reporter->onEvent($event);
            }
        }

        $reporter->finish();
        $document = \simplexml_load_string($output->buffer());

        Expect::that($document)
            ->because('JUnit output without RunFinished MUST remain valid')
            ->toBeInstanceOf(\SimpleXMLElement::class);

        Expect::that((string) $document['time'])
            ->because('test durations provide the total when RunFinished is absent')
            ->toBe('0.527000');
    }

    #[Test]
    public function anExplicitZeroRunDurationOverridesTheTestDurationTotal(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $reporter->onEvent(new TestFinished(new TestResult(
            new TestId('Acme\ZeroDurationTest', 'passes'),
            Outcome::Passed,
            0.5,
            0,
        ), 1.0));
        $reporter->onEvent(new RunFinished(
            'run-1',
            new ResultSummary(passed: 1),
            0.0,
            1.0,
        ));

        $reporter->finish();
        $document = \simplexml_load_string($output->buffer());

        Expect::that($document)
            ->because('JUnit output with an explicit zero run duration MUST remain valid')
            ->toBeInstanceOf(\SimpleXMLElement::class);

        Expect::that((string) $document['time'])
            ->because('an explicit zero run duration MUST override the test duration total')
            ->toBe('0.000000');
    }

    #[Test]
    public function invalidTextIsReplacedInNamesAndDiagnostics(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $result = new TestResult(
            new TestId('Acme\XmlTest', 'fails', "case\x01\xFF"),
            Outcome::Failed,
            0.001,
            0,
            failures: [
                new FailureDetail(
                    "message\x02\xFF",
                    "expected\x03\xFF",
                    "actual\x04\xFF",
                    new SourceLocation("/project/tests/\x05\xFFTest.php", 12),
                ),
            ],
        );

        $reporter->onEvent(new TestFinished($result, 1.0));
        $reporter->finish();
        $document = \simplexml_load_string($output->buffer());

        Expect::that($document)
            ->because('JUnit output MUST remain valid XML when diagnostics contain forbidden characters')
            ->toBeInstanceOf(\SimpleXMLElement::class);

        $case = SimpleXml::xpath($document, '/testsuites/testsuite/testcase')[0];
        $failure = SimpleXml::xpath($case, 'failure')[0];

        Expect::that((string) $case['name'])
            ->because('invalid text in test names is replaced')
            ->toBe("fails[case\u{FFFD}\u{FFFD}]");
        Expect::that((string) $failure['message'])
            ->because('invalid text in diagnostic attributes is replaced')
            ->toBe("message\u{FFFD}\u{FFFD}");
        Expect::that((string) $failure)
            ->because('invalid text in diagnostic content is replaced')
            ->toBe(
                "message\u{FFFD}\u{FFFD}\n"
                . "expected: expected\u{FFFD}\u{FFFD}\n"
                . "actual: actual\u{FFFD}\u{FFFD}\n"
                . "at /project/tests/\u{FFFD}\u{FFFD}Test.php:12",
            );
    }
}
