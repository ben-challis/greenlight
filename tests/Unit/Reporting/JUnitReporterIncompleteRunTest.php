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
use Greenlight\Tests\Support\SimpleXml;

final class JUnitReporterIncompleteRunTest
{
    #[Test]
    public function missingRunFinishedUsesTheSummedTestDuration(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $reporter->onEvent(new TestFinished(
            new TestResult(
                new TestId('Acme\InterruptedTest', 'first'),
                Outcome::Passed,
                0.25,
                1,
            ),
            1.0,
        ));
        $reporter->onEvent(new TestFinished(
            new TestResult(
                new TestId('Acme\InterruptedTest', 'second'),
                Outcome::Passed,
                0.5,
                1,
            ),
            2.0,
        ));

        $reporter->finish();

        Expect::that($output->buffer())
            ->because('incomplete JUnit streams MUST report the summed test duration')
            ->toContain(
                '<testsuites name="greenlight" tests="2" failures="0" errors="0" '
                . 'skipped="0" time="0.750000">',
            );
    }

    #[Test]
    public function summedDurationsSaturateBeforeTheyOverflow(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);

        foreach (['first', 'second'] as $method) {
            $reporter->onEvent(new TestFinished(
                new TestResult(
                    new TestId('Acme\LongRunningTest', $method),
                    Outcome::Passed,
                    \PHP_FLOAT_MAX,
                    1,
                ),
                1.0,
            ));
        }

        $reporter->finish();
        $document = \simplexml_load_string($output->buffer());

        Expect::that($document)
            ->because('overflow protection MUST preserve a valid JUnit document')
            ->toBeInstanceOf(\SimpleXMLElement::class);

        $suites = SimpleXml::xpath($document, '//testsuite');
        $maximum = \sprintf('%.6f', \PHP_FLOAT_MAX);

        Expect::that((string) $document['time'])
            ->because('the fallback run duration MUST remain a finite decimal')
            ->toBe($maximum);
        Expect::that($suites)
            ->because('the report contains the overflowing class suite')
            ->toHaveCount(1);

        if ($suites === []) {
            return;
        }

        Expect::that((string) $suites[0]['time'])
            ->because('the class duration MUST remain a finite decimal')
            ->toBe($maximum);
    }
}
