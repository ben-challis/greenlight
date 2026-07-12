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
use Greenlight\Tests\Support\TerminalEmulator;

final class TtyReporterTest
{
    #[Test]
    public function interleavedClassesFinalizeInPlaceWithAnsi(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: true, cursor: true, header: new RunHeader('dev-main', 'greenlight.php', 4242, phpVersion: '8.4.0'));

        // Two classes in flight at once, exactly what multiple workers produce.
        // Timestamps stay at least 0.05s apart so the redraw throttle never
        // swallows one of these events.
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

        Expect::that($screen)->toContain('Greenlight dev-main')
            ->and($screen)->toContain('PHP 8.4.0 | config: greenlight.php | workers: 2 | seed: 4242')
            // Only the failing class earns a permanent line; the pass just counts.
            ->and($screen)->not()->toContain('✓ App\AlphaTest')
            ->and($screen)->toContain('✗ App\BetaTest (1 test, 1 failed, 0.010s)')
            ->and($screen)->toContain('2 tests, 1 passed, 1 failed, 0 expectations');
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

        $terminal = new TerminalEmulator(retainColour: true);
        $terminal->write($output->buffer());

        Expect::that($terminal->screen())->toContain("(1 test, \x1b[33m1.500s\x1b[0m)");
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

        $terminal = new TerminalEmulator(retainColour: true);
        $terminal->write($output->buffer());

        Expect::that($terminal->screen())->toContain("\x1b[32m✓\x1b[0m App\AlphaTest (1 test, 0.010s)");
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

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());

        // The permanent skip line is followed by a blank line, then the
        // live window starts underneath it.
        $lines = $terminal->visibleLines();
        $skipLine = $this->indexOfLine($lines, '− App\GammaTest (1 test, skipped, 0.010s)');

        Expect::that($lines[$skipLine + 1] ?? null)->toBe('');
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

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());
        $lines = $terminal->visibleLines();

        $gammaLine = $this->indexOfLine($lines, '− App\GammaTest (1 test, skipped, 0.010s)');
        $deltaLine = $this->indexOfLine($lines, '− App\DeltaTest (1 test, skipped, 0.010s)');

        // The first permanent line opens with a blank line so it never butts
        // against the header; the second stacks directly under the first,
        // without a gap.
        Expect::that($lines[$gammaLine - 1] ?? null)->toBe('')
            ->and($deltaLine)->toBe($gammaLine + 1);
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

        $terminal = new TerminalEmulator(retainColour: true);
        $terminal->write($output->buffer());

        // Counter: done/planned plus a red failure count.
        Expect::that($terminal->screen())->toContain("1/4 tests, \x1b[31m1 failed\x1b[0m")
            // In-flight line: failure mark, dim name and running count so the
            // line reads as pending, elapsed since class start (1.5s crosses
            // the slow threshold, so it renders yellow).
            ->and($terminal->screen())->toContain("\x1b[31m✗\x1b[0m \x1b[2mApp\AlphaTest (1)\x1b[0m \x1b[33m1.500s\x1b[0m");
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

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());
        $screen = $terminal->screen();

        // Oldest class stays visible; the rest collapse into the overflow line.
        Expect::that($screen)->toContain('App\AlphaTest (0)')
            ->and($screen)->toContain('… and 2 more running')
            ->and($screen)->not()->toContain('App\BetaTest');
    }

    #[Test]
    public function windowCapacityClampsToTerminalHeightWithAFloor(): void
    {
        Expect::that(TtyReporter::windowCapacity(50))->toBe(10)
            ->and(TtyReporter::windowCapacity(12))->toBe(7)
            ->and(TtyReporter::windowCapacity(6))->toBe(3);
    }

    #[Test]
    public function tickAdvancesInFlightDurationsWithoutEvents(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: true);

        $reporter->onEvent(new RunStarted('run-1', 1, 1, 1.0));
        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->tick(3.5);

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());

        Expect::that($terminal->screen())->toContain('App\AlphaTest (0)')
            ->and($terminal->screen())->toContain('2.500s');
    }

    #[Test]
    public function tickWithoutCursorSupportWritesNothing(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: false);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->tick(2.0);

        Expect::that($output->buffer())->toBe('');
    }

    #[Test]
    public function tickWithNoClassesInFlightWritesNothing(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: true);

        $reporter->onEvent(new RunStarted('run-1', 1, 1, 1.0));
        $reporter->tick(2.0);

        Expect::that($output->buffer())->toBe('');
    }

    #[Test]
    public function redrawsInsideTheThrottleWindowAreSkipped(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: true);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $before = $output->buffer();

        $reporter->tick(1.01);

        Expect::that($output->buffer())->toBe($before);

        $reporter->tick(1.2);

        Expect::that($output->buffer())->not()->toBe($before);
    }

    #[Test]
    public function repaintsRewriteLinesInPlaceWithoutBlankingTheWindow(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: true);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $before = \strlen($output->buffer());

        $reporter->tick(1.2);
        $frame = \substr($output->buffer(), $before);

        // The repaint repositions over the previous three-line window and
        // clears each line right before rewriting it; blanking the whole
        // region first is what makes terminals flash mid-frame.
        Expect::that($frame)->toContain("\x1b[3A\r\x1b[2K")
            ->and($frame)->not()->toContain("\x1b[0J");
    }

    #[Test]
    public function classFinalizationRepaintsInOneFrameWithoutBlanking(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: true);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestClassStarted('App\BetaTest', 1.05));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Failed), 1.15));
        $before = \strlen($output->buffer());

        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.25));
        $frame = \substr($output->buffer(), $before);

        // The permanent line rides the same frame as the window repaint; a
        // separate erase-then-rebuild would flash the region blank.
        Expect::that($frame)->toContain("\x1b[4A\r\x1b[2K")
            ->and($frame)->toContain('✗ App\AlphaTest (1 test, 1 failed')
            ->and($frame)->not()->toContain("\x1b[0J");
    }

    #[Test]
    public function theCursorIsHiddenWhileLiveAndRestoredAtFinish(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: true);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));

        Expect::that($output->buffer())->toContain("\x1b[?25l")
            ->and($output->buffer())->not()->toContain("\x1b[?25h");

        $reporter->finish();

        Expect::that($output->buffer())->toContain("\x1b[?25h");
    }

    #[Test]
    public function classFinalizationBypassesTheThrottle(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, colour: false, cursor: true);

        $reporter->onEvent(new TestClassStarted('App\AlphaTest', 1.0));
        $reporter->onEvent(new TestFinished($this->result('App\AlphaTest', 'one', Outcome::Failed), 1.01));
        $reporter->onEvent(new TestClassFinished('App\AlphaTest', 1.02));

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());

        Expect::that($terminal->screen())->toContain('✗ App\AlphaTest (1 test, 1 failed');
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
            throw new \RuntimeException(\sprintf('Line "%s" was not found in the visible screen.', $line));
        }

        return $index;
    }
}
