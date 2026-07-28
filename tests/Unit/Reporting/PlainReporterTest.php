<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\PlainReporter;
use Greenlight\Reporting\RunHeader;

final class PlainReporterTest
{
    #[Test]
    public function cannedStreamRendersTheGoldenOutput(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new PlainReporter($output));

        $expected = <<<'TXT'
            Run run-1: 6 tests, 2 workers

            PASS Acme\CalculatorTest::addsIntegers (0.012s)
            FAIL Acme\CalculatorTest::subtractsIntegers (0.020s)
            PASS Acme\CalculatorTest::multipliesIntegers[large numbers] (0.340s)
            ERROR Acme\NetworkTest::connects (0.005s)
            SKIP Acme\NetworkTest::pings (0.000s)
            PASS Acme\NetworkTest::retriesFlakyEndpoint (0.150s) (attempts: 3)

            FAIL Acme\CalculatorTest::subtractsIntegers
              Failed asserting that two values are equal.
              expected: 2
              actual: 3
              at /project/tests/CalculatorTest.php:42

            ERROR Acme\NetworkTest::connects
              RuntimeException: Connection refused.
                Acme\NetworkTest::connect at /project/tests/NetworkTest.php:17
              at /project/tests/NetworkTest.php:17

            6 tests, 3 passed, 1 failed, 1 errored, 1 skipped, 11 expectations
            Time: 1.234s
            Workers: 2 spawned, 1 recycled (memory: 1)

            Skipped:
              Acme\NetworkTest::pings (Requires ext-redis.)
            TXT;

        Expect::that($output->buffer())->because('canned stream renders the golden output')->toBe($expected . "\n");
    }

    #[Test]
    public function headerLinePrecedesTheRunLineWhenProvided(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new PlainReporter($output, new RunHeader('0.4.0', 'greenlight.php', 7, phpVersion: '8.3.1')));

        Expect::that($output->buffer())->because('header line precedes the run line when provided')
            ->toStartWith("Greenlight 0.4.0\nPHP 8.3.1 | configuration: greenlight.php | workers: 2 | seed: 7\nRun run-1: 6 tests, 2 workers\n");
    }

    #[Test]
    public function identicalStreamsProduceByteIdenticalOutput(): void
    {
        $first = new BufferOutput();
        CannedStream::feed(new PlainReporter($first));

        $second = new BufferOutput();
        CannedStream::feed(new PlainReporter($second));

        Expect::that($first->buffer())->because('identical streams produce byte identical output')->toBe($second->buffer());
    }

    #[Test]
    public function outputContainsNoAnsiEscapes(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new PlainReporter($output));

        Expect::that($output->buffer())->because('output contains no ANSI escapes')->not()->toContain("\e");
    }

    #[Test]
    public function successfulRiskyTestsRenderExactGuidance(): void
    {
        $output = new BufferOutput();
        $reporter = new PlainReporter($output);
        $reporter->onEvent(new TestFinished(
            new TestResult(
                new TestId('Acme\\RiskyTest', 'passesWithoutExpectations'),
                Outcome::Passed,
                0.01,
                0,
                risky: true,
            ),
            1_750_000_000.5,
        ));
        $reporter->finish();

        $expected = <<<'TXT'
            PASS Acme\RiskyTest::passesWithoutExpectations (0.010s)

            Risky tests: 1
            These tests passed without a verified expectation.
            Add #[NoExpectations] to accept this result. Use --fail-on-risky to fail the run.
              Acme\RiskyTest::passesWithoutExpectations
            TXT;

        Expect::that($output->buffer())
            ->because('successful risky tests MUST render exact actionable guidance')
            ->toBe($expected . "\n");
    }
}
