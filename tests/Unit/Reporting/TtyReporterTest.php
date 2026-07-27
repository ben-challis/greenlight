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
use Greenlight\Expect\Fail;
use Greenlight\Reporting\RunHeader;
use Greenlight\Reporting\TtyReporter;
use Greenlight\Tests\Support\TerminalEmulator;

final class TtyReporterTest
{
    #[Test]
    public function interleavedClassesFinalizeInPlaceWithAnsi(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: true, cursor: true, header: new RunHeader('dev-main', 'greenlight.php', 4242, phpVersion: '8.4.0'));

        // Two classes are active at the same time, as with multiple workers.
        // Timestamps differ by at least 0.05 seconds. Thus, the redraw limit
        // does not omit these events.
        $reporter->onEvent(new RunStarted('run-1', 2, 2, 1.0));
        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestClassStarted('App\BetaTest', 1.05));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.15));
        $reporter->onEvent(new TestFinished($this->result('App\BetaTest', 'one', Outcome::Failed), 1.25));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.35));
        $reporter->onEvent(new TestClassFinished('App\BetaTest', 1.45));
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1, failed: 1), 0.4, 1.55));
        $reporter->finish();

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());
        $screen = $terminal->screen();

        Expect::that($screen)->because('interleaved classes finalize in place with ANSI')->toContain('Greenlight dev-main')
            ->toContain('PHP 8.4.0 | configuration: greenlight.php | workers: 2 | seed: 4242')
            // Only the failed class has a permanent line. The passed class
            // changes only the count.
            ->not()->toContain('✓ App\AlphaTest')
            ->toContain('✗ App\BetaTest (1 test, 1 failed, 0.010s)')
            ->toContain('2 tests, 1 passed, 1 failed, 0 expectations');
    }

    #[Test]
    public function withoutAnsiOnlyFinalizedLinesAreWritten(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: false);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.2));
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1), 0.1, 1.3));
        $reporter->finish();

        $buffer = $output->buffer();

        Expect::that($buffer)->because('without ANSI only finalized lines are written')->not()->toContain("\x1b[")
            ->toContain("✓ App\AlphaTest (1 test, 0.010s)\n")
            ->toContain('1 test, 1 passed, 0 expectations');
    }

    #[Test]
    public function zeroResultCategoriesAreOmittedFromTheSummary(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: false);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.2));
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1), 0.1, 1.3));
        $reporter->finish();

        Expect::that($output->buffer())->because('zero result categories are omitted from the summary')->not()->toContain('failed')
            ->not()->toContain('errored')
            ->not()->toContain('skipped');
    }

    #[Test]
    public function onlySuccessfulRiskyResultsAppearInTheFinalGuidance(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: false);

        $reporter->onEvent(new TestFinished(new TestResult(
            new TestId('App\RiskyTest', 'passes'),
            Outcome::Passed,
            0.01,
            0,
            risky: true,
        ), 1.0));
        $reporter->onEvent(new TestFinished(new TestResult(
            new TestId('App\RiskyTest', 'fails'),
            Outcome::Failed,
            0.01,
            0,
            risky: true,
        ), 1.1));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('only successful risky results need expectation guidance')
            ->toEndWith(
                "\nRisky tests: 1\n"
                . "These tests passed without a verified expectation.\n"
                . "Add #[NoExpectations] to accept this result. Use --fail-on-risky to fail the run.\n"
                . "  App\\RiskyTest::passes\n",
            );
    }

    #[Test]
    public function skippedTestsAreUnambiguousAndListedWithReasons(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: false);

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

        // A class with only skipped tests reads "skipped". A mixed class shows
        // the number of skipped tests.
        Expect::that($buffer)->because('skipped tests are unambiguous and listed with reasons')->toContain('− App\GammaTest (1 test, skipped, 0.010s)')
            ->toContain('✓ App\DeltaTest (2 tests, 1 skipped, 0.020s)')
            ->toContain('3 tests, 1 passed, 2 skipped, 0 expectations')
            ->toContain("Skipped:\n  App\GammaTest::one (xdebug not loaded)\n  App\DeltaTest::two (no reason given)");
    }

    #[Test]
    public function workersLineOmitsZeroRecycledAndDisappearsWhenNoneSpawned(): void
    {
        $spawned = new BufferOutput();
        $reporter = new TtyReporter($spawned, color: false, cursor: false);
        $reporter->onEvent(new WorkerSpawned('w-1', 101, 1.0));
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1), 0.1, 1.3));
        $reporter->finish();

        Expect::that($spawned->buffer())->because('workers line omits zero recycled and disappears when none spawned')->toContain("Workers: 1 spawned\n")
            ->not()->toContain('recycled');

        $inProcess = new BufferOutput();
        $reporter = new TtyReporter($inProcess, color: false, cursor: false);
        $reporter->onEvent(new RunFinished('run-1', new ResultSummary(passed: 1), 0.1, 1.3));
        $reporter->finish();

        Expect::that($inProcess->buffer())->because('workers line omits zero recycled and disappears when none spawned')->not()->toContain('Workers:');
    }

    #[Test]
    public function slowDurationsAreColoredOnClassLines(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: true, cursor: true, verbose: true);

        $reporter->onEvent(new TestClassStarted('App\SlowTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\SlowTest', 'one', Outcome::Passed, 1.5), 1.1));
        $reporter->onEvent(new TestClassFinished('App\SlowTest', 1.2));
        $reporter->finish();

        $terminal = new TerminalEmulator(retainColor: true);
        $terminal->write($output->buffer());

        Expect::that($terminal->screen())->because('slow durations are colored on class lines')->toContain("(1 test, \x1b[33m1.500s\x1b[0m)");
    }

    #[Test]
    public function verboseRestoresAPermanentLinePerClass(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: true, cursor: true, verbose: true);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.2));
        $reporter->finish();

        $terminal = new TerminalEmulator(retainColor: true);
        $terminal->write($output->buffer());

        Expect::that($terminal->screen())->because('verbose restores a permanent line per class')->toContain("\x1b[32m✓\x1b[0m App\AlphaTest (1 test, 0.010s)");
    }

    #[Test]
    public function aBlankLineSeparatesPermanentLinesFromTheLiveWindow(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: true);

        $reporter->onEvent(new RunStarted('run-1', 2, 1, 1.0));
        $reporter->onEvent(new TestClassStarted('App\GammaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->skipped('App\GammaTest', 'one', null), 1.1));
        $reporter->onEvent(new TestClassStarted('App\DeltaTest', 1.2));
        $reporter->onEvent(new TestClassFinished('App\GammaTest', 1.3));

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());

        // A blank line occurs after the permanent skip line. The live window
        // starts below the blank line.
        $lines = $terminal->visibleLines();
        $skipLine = $this->indexOfLine($lines, '− App\GammaTest (1 test, skipped, 0.010s)');

        Expect::that($lines[$skipLine + 1] ?? null)->because('a blank line separates permanent lines from the live window')->toBe('');
    }

    #[Test]
    public function theFirstPermanentLineGetsAGapAndLaterOnesStack(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: true, header: new RunHeader('dev-main', 'greenlight.php', null, phpVersion: '8.4.0'));

        $reporter->onEvent(new RunStarted('run-1', 4, 1, 1.0));
        $reporter->onEvent(new TestClassStarted('App\GammaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->skipped('App\GammaTest', 'one', null), 1.1));
        $reporter->onEvent(new TestClassFinished('App\GammaTest', 1.2));
        $reporter->onEvent(new TestClassStarted('App\DeltaTest', 1.3));
        $reporter->onEvent(new TestFinished($this->skipped('App\DeltaTest', 'one', null), 1.4));
        $reporter->onEvent(new TestClassFinished('App\DeltaTest', 1.5));

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());
        $lines = $terminal->visibleLines();

        $gammaLine = $this->indexOfLine($lines, '− App\GammaTest (1 test, skipped, 0.010s)');
        $deltaLine = $this->indexOfLine($lines, '− App\DeltaTest (1 test, skipped, 0.010s)');

        // A blank line separates the first permanent line from the header. The
        // second permanent line occurs directly below the first.
        Expect::that($lines[$gammaLine - 1] ?? null)->because('the first permanent line gets a gap and later ones stack')->toBe('')
            ->and($deltaLine)->toBe($gammaLine + 1);
    }

    #[Test]
    public function noColorKeepsTheLiveWindowWithoutColorCodes(): void
    {
        // In the NO_COLOR case, cursor control remains and color is absent.
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: true);

        $reporter->onEvent(new RunStarted('run-1', 2, 1, 1.0));
        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.2));
        $reporter->finish();

        $buffer = $output->buffer();

        Expect::that($buffer)->because('no color keeps the live window without color codes')->toContain("\x1b[0J")
            ->not()->toContain("\x1b[32m")
            ->not()->toContain("\x1b[31m")
            ->not()->toContain("\x1b[33m");
    }

    #[Test]
    public function withoutCursorEveryClassStillAppendsALine(): void
    {
        // On a non-TTY, --reporter=tty uses output that only adds new lines.
        // Unavailable live output MUST NOT hide information.
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: false);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Passed), 1.1));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.2));
        $reporter->finish();

        Expect::that($output->buffer())->because('without cursor every class still appends a line')->toContain("✓ App\AlphaTest (1 test, 0.010s)\n")
            ->not()->toContain("\x1b[");
    }

    #[Test]
    public function theWindowShowsACounterAndInFlightClassesWithElapsedTime(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: true, cursor: true);

        $reporter->onEvent(new RunStarted('run-1', 4, 2, 10.0));
        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 10.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Failed), 11.5));

        $terminal = new TerminalEmulator(retainColor: true);
        $terminal->write($output->buffer());

        // The counter shows completed and planned tests with a red failure
        // count.
        Expect::that($terminal->screen())->because('the window shows a counter and in flight classes with elapsed time')->toContain("1/4 tests, \x1b[31m1 failed\x1b[0m")
            // The active line has a failure mark, dim class name, and active
            // count. These items show that no result is available. The elapsed
            // time is 1.5 seconds, which exceeds the slow limit and appears
            // yellow.
            ->toContain("\x1b[31m✗\x1b[0m \x1b[2mApp\AlphaTest (1)\x1b[0m \x1b[33m1.500s\x1b[0m");
    }

    #[Test]
    public function inFlightClassesBeyondCapacityCollapseIntoAnOverflowLine(): void
    {
        $output = new BufferOutput();
        // Eight terminal rows limit the window to three lines. These lines are
        // the counter, one class, and the overflow.
        $reporter = new TtyReporter($output, color: false, cursor: true, terminalRows: 8);

        $reporter->onEvent(new RunStarted('run-1', 9, 3, 1.0));
        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestClassStarted('App\BetaTest', 1.1));
        $reporter->onEvent(new TestClassStarted('App\GammaTest', 1.2));

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());
        $screen = $terminal->screen();

        // The oldest class remains visible. The overflow line represents the
        // other classes.
        Expect::that($screen)->because('in flight classes beyond capacity collapse into an overflow line')->toContain('App\AlphaTest (0)')
            ->toContain('… and 2 more running')
            ->not()->toContain('App\BetaTest');
    }

    #[Test]
    public function windowCapacityClampsToTerminalHeightWithAFloor(): void
    {
        Expect::that(TtyReporter::windowCapacity(50))->because('window capacity clamps to terminal height with a floor')->toBe(10)
            ->and(TtyReporter::windowCapacity(12))->toBe(7)
            ->and(TtyReporter::windowCapacity(6))->toBe(3);
    }

    #[Test]
    public function tickAdvancesInFlightDurationsWithoutEvents(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: true);

        $reporter->onEvent(new RunStarted('run-1', 1, 1, 1.0));
        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->tick(3.5);

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());

        Expect::that($terminal->screen())->because('tick advances in flight durations without events')->toContain('App\AlphaTest (0)')
            ->toContain('2.500s');
    }

    #[Test]
    public function tickWithoutCursorSupportWritesNothing(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: false);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->tick(2.0);

        Expect::that($output->buffer())->because('tick without cursor support writes nothing')->toBe('');
    }

    #[Test]
    public function tickWithNoClassesInFlightWritesNothing(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: true);

        $reporter->onEvent(new RunStarted('run-1', 1, 1, 1.0));
        $reporter->tick(2.0);

        Expect::that($output->buffer())->because('tick with no classes in flight writes nothing')->toBe('');
    }

    #[Test]
    public function redrawsInsideTheThrottleWindowAreSkipped(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: true);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $before = $output->buffer();

        $reporter->tick(1.01);

        Expect::that($output->buffer())->because('redraws inside the throttle window are skipped')->toBe($before);

        $reporter->tick(1.2);

        Expect::that($output->buffer())->because('redraws inside the throttle window are skipped')->not()->toBe($before);
    }

    #[Test]
    public function repaintsRewriteLinesInPlaceWithoutBlankingTheWindow(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: true);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $before = \strlen($output->buffer());

        $reporter->tick(1.2);
        $frame = \substr($output->buffer(), $before);

        // Repaint moves over the previous three-line window. It clears each
        // line immediately before it writes the line again. A separate clear
        // of the complete area causes a terminal flash.
        Expect::that($frame)->because('repaints rewrite lines in place without blanking the window')->toContain("\x1b[3A\r\x1b[2K")
            ->not()->toContain("\x1b[0J");
    }

    #[Test]
    public function classFinalizationRepaintsInOneFrameWithoutBlanking(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: true);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestClassStarted('App\BetaTest', 1.05));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Failed), 1.15));
        $before = \strlen($output->buffer());

        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.25));
        $frame = \substr($output->buffer(), $before);

        // The permanent line and window repaint use the same frame. A separate
        // erase and rebuild operation causes a blank flash.
        Expect::that($frame)->because('class finalization repaints in one frame without blanking')->toContain("\x1b[4A\r\x1b[2K")
            ->toContain('✗ App\AlphaTest (1 test, 1 failed')
            ->not()->toContain("\x1b[0J");
    }

    #[Test]
    public function theCursorIsHiddenWhileLiveAndRestoredAtFinish(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: true);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));

        Expect::that($output->buffer())->because('the cursor is hidden while live and restored at finish')->toContain("\x1b[?25l")
            ->not()->toContain("\x1b[?25h");

        $reporter->finish();

        Expect::that($output->buffer())->because('the cursor is hidden while live and restored at finish')->toContain("\x1b[?25h");
    }

    #[Test]
    public function classFinalizationBypassesTheThrottle(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: true);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Failed), 1.01));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.02));

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());

        Expect::that($terminal->screen())->because('class finalization bypasses the throttle')->toContain('✗ App\AlphaTest (1 test, 1 failed');
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

    /**
     * @param list<string> $lines
     */
    private function indexOfLine(array $lines, string $line): int
    {
        $index = \array_search($line, $lines, strict: true);

        if ($index === false) {
            Fail::because(\sprintf('Line "%s" was not found in the visible screen.', $line));
        }

        return $index;
    }
}
