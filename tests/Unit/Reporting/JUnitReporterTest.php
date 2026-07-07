<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JUnitReporter;

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
              <testsuite name="Acme\CalculatorTest" tests="3" failures="1" errors="0" skipped="0" time="0.372000">
                <testcase name="addsIntegers" classname="Acme\CalculatorTest" time="0.012000"/>
                <testcase name="subtractsIntegers" classname="Acme\CalculatorTest" time="0.020000">
                  <failure type="failure" message="Failed asserting that two values are equal.">expected: 2
            actual: 3
            at /project/tests/CalculatorTest.php:42</failure>
                </testcase>
                <testcase name="multipliesIntegers[large numbers]" classname="Acme\CalculatorTest" time="0.340000"/>
              </testsuite>
              <testsuite name="Acme\NetworkTest" tests="3" failures="0" errors="1" skipped="1" time="0.155000">
                <testcase name="connects" classname="Acme\NetworkTest" time="0.005000">
                  <error type="RuntimeException" message="Connection refused.">Acme\NetworkTest::connect at /project/tests/NetworkTest.php:17
            at /project/tests/NetworkTest.php:17</error>
                </testcase>
                <testcase name="pings" classname="Acme\NetworkTest" time="0.000000">
                  <skipped message="Requires ext-redis."/>
                </testcase>
                <testcase name="retriesFlakyEndpoint" classname="Acme\NetworkTest" time="0.150000"/>
              </testsuite>
            </testsuites>
            TXT;

        new Expect()->that($output->buffer())->toBe($expected . "\n");
    }

    #[Test]
    public function xmlParsesAndCountsMatchTheStream(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new JUnitReporter($output));

        $document = \simplexml_load_string($output->buffer());

        new Expect()->that($document)->toBeInstanceOf(\SimpleXMLElement::class);

        if ($document === false) {
            return;
        }

        new Expect()
            ->that((string) $document['tests'])->toBe('6')
            ->and((string) $document['failures'])->toBe('1')
            ->and((string) $document['errors'])->toBe('1')
            ->and((string) $document['skipped'])->toBe('1')
            ->and($document->xpath('//testcase'))->toHaveCount(6)
            ->and($document->xpath('//testsuite'))->toHaveCount(2)
            ->and($document->xpath('//failure'))->toHaveCount(1)
            ->and($document->xpath('//error'))->toHaveCount(1)
            ->and($document->xpath('//skipped'))->toHaveCount(1);
    }
}
