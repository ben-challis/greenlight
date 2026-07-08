<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\RunHeader;
use Greenlight\Reporting\TtyReporter;

final class TtyReporterTest
{
    #[Test]
    public function interleavedClassesFinalizeInPlaceWithAnsi(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: true, cursor: true, header: new RunHeader('dev-main', 'greenlight.php', 4242, phpVersion: '8.4.0'));

        // Two classes in flight at once, exactly what multiple workers produce.
        $reporter->onEvent(new RunStarted('run-1', 2, 2, 1.0));
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
            ->and($buffer)->toContain('Greenlight dev-main | PHP 8.4.0 | config: greenlight.php | seed: 4242 | workers: 2')
            // Only the failing class earns a permanent line; the pass just counts.
            ->and($buffer)->not()->toContain("\x1b[32m✓\x1b[0m App\AlphaTest")
            ->and($buffer)->toContain("\x1b[31m✗\x1b[0m App\BetaTest (1 test, 1 failed, 0.010s)")
            ->and($buffer)->toContain("2 tests, \x1b[32m1 passed\x1b[0m, \x1b[31m1 failed\x1b[0m, 0 expectations");
    }

    #[Test]
    public function withoutAnsiOnlyFinalizedLinesAreWritten(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: false);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.2));
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1), 0.1, 1.3));
        $reporter->finish();

        $buffer = $output->buffer();

        Expect::that($buffer)->not()->toContain("\x1b[")
            ->and($buffer)->toContain("✓ App\AlphaTest (1 test, 0.010s)\n")
            ->and($buffer)->toContain('1 test, 1 passed, 0 expectations');
    }

    #[Test]
    public function zeroResultCategoriesAreOmittedFromTheSummary(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: false);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.2));
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1), 0.1, 1.3));
        $reporter->finish();

        Expect::that($output->buffer())->not()->toContain('failed')
            ->and($output->buffer())->not()->toContain('errored')
            ->and($output->buffer())->not()->toContain('skipped');
    }

    #[Test]
    public function skippedTestsAreUnambiguousAndListedWithReasons(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: false);

        $reporter->onEvent(new TestClassStarted('App\GammaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->skipped('App\GammaTest', 'one', 'xdebug not loaded'), 1.1));
        $reporter->onEvent(new TestClassFinished('App\GammaTest', 1.2));
        $reporter->onEvent(new TestClassStarted('App\DeltaTest', 1.3));
        $reporter->onEvent(new TestFinished($this->result('App\DeltaTest', 'one', Outcome::Passed), 1.4));
        $reporter->onEvent(new TestFinished($this->skipped('App\DeltaTest', 'two', null), 1.5));
        $reporter->onEvent(new TestClassFinished('App\DeltaTest', 1.6));
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1, skipped: 2), 0.2, 1.7));
        $reporter->finish();

        $buffer = $output->buffer();

        // A fully skipped class reads "skipped", a mixed one counts them.
        Expect::that($buffer)->toContain('− App\GammaTest (1 test, skipped, 0.010s)')
            ->and($buffer)->toContain('✓ App\DeltaTest (2 tests, 1 skipped, 0.020s)')
            ->and($buffer)->toContain('3 tests, 1 passed, 2 skipped, 0 expectations')
            ->and($buffer)->toContain("Skipped:\n  App\GammaTest::one (xdebug not loaded)\n  App\DeltaTest::two (no reason given)");
    }

    #[Test]
    public function workersLineOmitsZeroRecycledAndDisappearsWhenNoneSpawned(): void
    {
        $spawned = new BufferOutput();
        $reporter = new TtyReporter($spawned, colour: false, cursor: false);
        $reporter->onEvent(new WorkerSpawned('w-1', 101, 1.0));
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1), 0.1, 1.3));
        $reporter->finish();

        Expect::that($spawned->buffer())->toContain("Workers: 1 spawned\n")
            ->and($spawned->buffer())->not()->toContain('recycled');

        $inProcess = new BufferOutput();
        $reporter = new TtyReporter($inProcess, colour: false, cursor: false);
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1), 0.1, 1.3));
        $reporter->finish();

        Expect::that($inProcess->buffer())->not()->toContain('Workers:');
    }

    #[Test]
    public function slowDurationsAreColouredOnClassLines(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: true, cursor: true, verbose: true);

        $reporter->onEvent(new TestClassStarted('App\SlowTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\SlowTest', 'one', Outcome::Passed, 1.5), 1.1));
        $reporter->onEvent(new TestClassFinished('App\SlowTest', 1.2));
        $reporter->finish();

        Expect::that($output->buffer())->toContain("(1 test, \x1b[33m1.500s\x1b[0m)");
    }

    #[Test]
    public function verboseRestoresAPermanentLinePerClass(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: true, cursor: true, verbose: true);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.2));
        $reporter->finish();

        Expect::that($output->buffer())->toContain("\x1b[32m✓\x1b[0m App\AlphaTest (1 test, 0.010s)");
    }

    #[Test]
    public function aBlankLineSeparatesPermanentLinesFromTheLiveWindow(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: true);

        $reporter->onEvent(new RunStarted('run-1', 2, 1, 1.0));
        $reporter->onEvent(new TestClassStarted('App\GammaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->skipped('App\GammaTest', 'one', null), 1.1));
        $reporter->onEvent(new TestClassStarted('App\DeltaTest', 1.2));
        $reporter->onEvent(new TestClassFinished('App\GammaTest', 1.3));

        // The permanent skip line is followed by a blank line, then the window.
        Expect::that($output->buffer())->toContain("− App\GammaTest (1 test, skipped, 0.010s)\n\n");
    }

    #[Test]
    public function theFirstPermanentLineGetsAGapAndLaterOnesStack(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: true, header: new RunHeader('dev-main', 'greenlight.php', null, phpVersion: '8.4.0'));

        $reporter->onEvent(new RunStarted('run-1', 4, 1, 1.0));
        $reporter->onEvent(new TestClassStarted('App\GammaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->skipped('App\GammaTest', 'one', null), 1.1));
        $reporter->onEvent(new TestClassFinished('App\GammaTest', 1.2));
        $reporter->onEvent(new TestClassStarted('App\DeltaTest', 1.3));
        $reporter->onEvent(new TestFinished($this->skipped('App\DeltaTest', 'one', null), 1.4));
        $reporter->onEvent(new TestClassFinished('App\DeltaTest', 1.5));

        $buffer = $output->buffer();

        // After erasing the window the first permanent line opens with a
        // blank line so it never butts against the header; the second stacks
        // directly under the first without another gap.
        Expect::that($buffer)->toContain("\x1b[0J\n− App\GammaTest (1 test, skipped, 0.010s)")
            ->and($buffer)->toContain("\x1b[0J− App\DeltaTest (1 test, skipped, 0.010s)");
    }

    #[Test]
    public function noColourKeepsTheLiveWindowWithoutColourCodes(): void
    {
        // The NO_COLOR matrix row: cursor control stays, colour goes.
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: true);

        $reporter->onEvent(new RunStarted('run-1', 2, 1, 1.0));
        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.2));
        $reporter->finish();

        $buffer = $output->buffer();

        Expect::that($buffer)->toContain("\x1b[0J")
            ->and($buffer)->not()->toContain("\x1b[32m")
            ->and($buffer)->not()->toContain("\x1b[31m")
            ->and($buffer)->not()->toContain("\x1b[33m");
    }

    #[Test]
    public function withoutCursorEveryClassStillAppendsALine(): void
    {
        // --reporter=tty on a non-TTY degrades to append-only output; nothing
        // may become invisible just because the live window is unavailable.
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: false);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.2));
        $reporter->finish();

        Expect::that($output->buffer())->toContain("✓ App\AlphaTest (1 test, 0.010s)\n")
            ->and($output->buffer())->not()->toContain("\x1b[");
    }

    #[Test]
    public function theWindowShowsACounterAndInFlightClassesWithElapsedTime(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: true, cursor: true);

        $reporter->onEvent(new RunStarted('run-1', 4, 2, 10.0));
        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 10.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Failed), 11.5));

        $buffer = $output->buffer();

        // Counter: done/planned plus a red failure count.
        Expect::that($buffer)->toContain("1/4 tests, \x1b[31m1 failed\x1b[0m")
            // In-flight line: failure mark, dim name and running count so the
            // line reads as pending, elapsed since class start (1.5s crosses
            // the slow threshold, so it renders yellow).
            ->and($buffer)->toContain("\x1b[31m✗\x1b[0m \x1b[2mApp\AlphaTest (1)\x1b[0m \x1b[33m1.500s\x1b[0m");
    }

    #[Test]
    public function inFlightClassesBeyondCapacityCollapseIntoAnOverflowLine(): void
    {
        $output = new BufferOutput();
        // 8 terminal rows clamp the window to 3 lines: counter + 1 class + overflow.
        $reporter = new TtyReporter($output, colour: false, cursor: true, terminalRows: 8);

        $reporter->onEvent(new RunStarted('run-1', 9, 3, 1.0));
        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestClassStarted('App\BetaTest', 1.1));
        $reporter->onEvent(new TestClassStarted('App\GammaTest', 1.2));

        $tail = \substr($output->buffer(), (int) \strrpos($output->buffer(), "\x1b[0J"));

        // Oldest class stays visible; the rest collapse into the overflow line.
        Expect::that($tail)->toContain('App\AlphaTest (0)')
            ->and($tail)->toContain('… and 2 more running')
            ->and($tail)->not()->toContain('App\BetaTest');
    }

    #[Test]
    public function windowCapacityClampsToTerminalHeightWithAFloor(): void
    {
        Expect::that(TtyReporter::windowCapacity(50))->toBe(10)
            ->and(TtyReporter::windowCapacity(12))->toBe(7)
            ->and(TtyReporter::windowCapacity(6))->toBe(3);
    }

    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     */
    private function result(string $class, string $method, Outcome $outcome, float $duration = 0.01): TestResult
    {
        return new TestResult(new TestId($class, $method), $outcome, $duration, 0);
    }

    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     */
    private function skipped(string $class, string $method, ?string $reason): TestResult
    {
        return new TestResult(new TestId($class, $method), Outcome::Skipped, 0.01, 0, skipReason: $reason);
    }
}
