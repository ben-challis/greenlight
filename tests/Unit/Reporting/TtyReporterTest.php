<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\TtyReporter;

final class TtyReporterTest
{
    #[Test]
    public function interleavedClassesFinalizeInPlaceWithAnsi(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, ansi: true, seed: 4242);

        // Two classes in flight at once, exactly what multiple workers produce.
        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestClassStarted('App\BetaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestFinished($this->result('App\BetaTest', 'one', Outcome::Failed), 1.2));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.3));
        $reporter->onEvent(new TestClassFinished('App\BetaTest', 1.4));
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1, failed: 1), 0.4, 1.5));
        $reporter->finish();

        $buffer = $output->buffer();

        // The live region is erased and redrawn: cursor-up plus clear-to-end.
        Expect::that($buffer)->toContain("\x1b[2A\r\x1b[0J")
            ->and($buffer)->toContain("\x1b[32m✓\x1b[0m App\AlphaTest (1 tests, 0.010s)")
            ->and($buffer)->toContain("\x1b[31m✗\x1b[0m App\BetaTest (1 tests, 1 failed, 0.010s)")
            ->and($buffer)->toContain('Tests: 2, Passed: 1, Failed: 1, Errored: 0, Skipped: 0')
            ->and($buffer)->toContain("Seed: 4242\n");

        // A live line for the still-running class carries its running count.
        Expect::that($buffer)->toContain('App\BetaTest (1)');
    }

    #[Test]
    public function withoutAnsiOnlyFinalizedLinesAreWritten(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, ansi: false);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.2));
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1), 0.1, 1.3));
        $reporter->finish();

        $buffer = $output->buffer();

        Expect::that($buffer)->not()->toContain("\x1b[")
            ->and($buffer)->toContain("✓ App\AlphaTest (1 tests, 0.010s)\n")
            ->and($buffer)->toContain('Tests: 1, Passed: 1, Failed: 0, Errored: 0, Skipped: 0');
    }

    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     */
    private function result(string $class, string $method, Outcome $outcome): TestResult
    {
        return new TestResult(new TestId($class, $method), $outcome, 0.01, 0);
    }
}
