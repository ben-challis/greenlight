<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\RunFinished;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Reporting\PlainReporter;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\TtyReporter;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

final readonly class ReporterExpectationOverflowTest
{
    #[Test]
    public function plainSummarySaturatesExpectationOverflow(): void
    {
        $output = new BufferOutput();
        $reporter = new PlainReporter($output);
        $this->recordOverflowingExpectations($reporter);
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 2), 0.0, 1.2));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('the plain summary MUST keep an overflowing expectation total representable')
            ->toContain(\sprintf('%d expectations', \PHP_INT_MAX));
    }

    #[Test]
    public function ttySummarySaturatesExpectationOverflow(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: false);
        $this->recordOverflowingExpectations($reporter);
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 2), 0.0, 1.2));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('the TTY summary MUST keep an overflowing expectation total representable')
            ->toContain(\sprintf('%d expectations', \PHP_INT_MAX));
    }

    #[Test]
    public function junitSummarySaturatesExpectationOverflow(): void
    {
        $output = new BufferOutput();
        $reporter = new JUnitReporter($output);
        $this->recordOverflowingExpectations($reporter);
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('the JUnit summary MUST keep an overflowing assertion total representable')
            ->toContain(\sprintf('assertions="%d"', \PHP_INT_MAX));
    }

    private function recordOverflowingExpectations(Reporter $reporter): void
    {
        $reporter->onEvent(new TestFinished(new TestResult(
            new TestId('Acme\\OverflowTest', 'maximum'),
            Outcome::Passed,
            0.0,
            0,
            expectations: \PHP_INT_MAX,
        ), 1.0));
        $reporter->onEvent(new TestFinished(new TestResult(
            new TestId('Acme\\OverflowTest', 'oneMore'),
            Outcome::Passed,
            0.0,
            0,
            expectations: 1,
        ), 1.1));
    }
}
