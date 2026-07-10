<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;

/**
 * A parallel-aware live display for interactive terminals.
 *
 * The live region holds a progress counter (done/planned, failure and skip
 * tints) and one dim line per in-flight class, oldest first, each with a
 * running count and an elapsed time that escalates through the slow-colour
 * thresholds. Capacity clamps to min(10, terminal rows - 5); classes past
 * capacity collapse into a single overflow line. A leading blank line
 * separates the window from the permanent scrollback above it.
 *
 * The live region is redrawn on events and on external ticks (see Ticking),
 * throttled to one repaint per 50ms. Each repaint is a single write that
 * rewrites every line in place, so the window never passes through a blank
 * state the terminal could paint as flicker. Permanent class lines ride the
 * same frame as the repaint that retires their class, and the terminal
 * cursor stays hidden from the first frame until finish(). The spinner
 * advances once per actual repaint.
 *
 * In bounded mode a cleanly passing class prints nothing permanent; only
 * classes containing failures or skips append a line, the moment they
 * finish. verbose restores a permanent line per class. Without cursor
 * support the live region is skipped and every class appends its line, so
 * output degrades to append-only rather than losing information.
 *
 * @internal
 */
final class TtyReporter implements Reporter, Ticking
{
    private const array SPINNER = ['|', '/', '-', '\\'];

    private const float REDRAW_INTERVAL_SECONDS = 0.05;

    /**
     * @var array<string, array{done: int, failed: int, skipped: int, duration: float, startedAt: float}>
     */
    private array $live = [];

    /**
     * @var list<TestResult>
     */
    private array $problems = [];

    /**
     * @var list<TestResult>
     */
    private array $skipped = [];

    /**
     * @var list<non-empty-string>
     */
    private array $risky = [];

    private int $drawnLines = 0;

    private bool $scrollbackStarted = false;

    private int $spinnerFrame = 0;

    private int $workersSpawned = 0;

    private int $workersRecycled = 0;

    /**
     * @var non-negative-int
     */
    private int $expectations = 0;

    private ?RunFinished $runFinished = null;

    private readonly Style $style;

    private readonly SlowTests $slowTests;

    private int $plannedTests = 0;

    private int $finishedTests = 0;

    private int $failedTests = 0;

    private int $skippedTests = 0;

    private float $lastEventAt = 0.0;

    private float $lastDrawAt = -\INF;

    private bool $cursorHidden = false;

    private readonly int $windowCapacity;

    public function __construct(
        private readonly Output\Output $output,
        bool $colour,
        private readonly bool $cursor,
        private readonly ?RunHeader $header = null,
        bool $extendedSlowTests = false,
        private readonly bool $verbose = false,
        int $terminalRows = 24,
    ) {
        $this->style = new Style($colour);
        $this->slowTests = new SlowTests($extendedSlowTests);
        $this->windowCapacity = self::windowCapacity($terminalRows);
    }

    /**
     * At most ten lines, leaving headroom on short terminals, never fewer
     * than three (counter, one class, overflow).
     */
    public static function windowCapacity(int $terminalRows): int
    {
        return \max(3, \min(10, $terminalRows - 5));
    }

    #[\Override]
    public function onEvent(Event $event): void
    {
        if ($event instanceof RunStarted) {
            $this->lastEventAt = $event->occurredAt;
            $this->plannedTests = $event->plannedTests;

            if ($this->header instanceof RunHeader) {
                // The window's own leading blank line provides the gap in
                // cursor mode; append-only output needs it written here.
                $this->output->write($this->header->render($event->workers, $this->style) . ($this->cursor ? "\n" : "\n\n"));
            }

            return;
        }

        if ($event instanceof TestClassStarted) {
            $this->lastEventAt = $event->occurredAt;
            $this->live[$event->class] = ['done' => 0, 'failed' => 0, 'skipped' => 0, 'duration' => 0.0, 'startedAt' => $event->occurredAt];
            $this->redraw();

            return;
        }

        if ($event instanceof TestFinished) {
            $this->slowTests->record($event);
            $result = $event->result;
            $this->expectations += $result->expectations;
            $class = $result->id->class;
            $this->lastEventAt = $event->occurredAt;
            ++$this->finishedTests;

            if (!$result->outcome->isSuccessful()) {
                ++$this->failedTests;
            } elseif ($result->outcome === Outcome::Skipped) {
                ++$this->skippedTests;
            }

            if (isset($this->live[$class])) {
                ++$this->live[$class]['done'];
                $this->live[$class]['duration'] += $result->durationSeconds;

                if (!$result->outcome->isSuccessful()) {
                    ++$this->live[$class]['failed'];
                } elseif ($result->outcome === Outcome::Skipped) {
                    ++$this->live[$class]['skipped'];
                }
            }

            if (!$result->outcome->isSuccessful()) {
                $this->problems[] = $result;
            }

            if ($result->outcome === Outcome::Skipped) {
                $this->skipped[] = $result;
            }

            if ($result->risky && $result->outcome->isSuccessful() && ($id = (string) $result->id) !== '') {
                $this->risky[] = $id;
            }

            $this->redraw();

            return;
        }

        if ($event instanceof TestClassFinished) {
            $this->lastEventAt = $event->occurredAt;
            $this->finalizeClass($event->class);

            return;
        }

        if ($event instanceof WorkerSpawned) {
            ++$this->workersSpawned;

            return;
        }

        if ($event instanceof WorkerRecycled) {
            ++$this->workersRecycled;

            return;
        }

        if ($event instanceof RunFinished) {
            $this->runFinished = $event;
        }
    }

    #[\Override]
    public function tick(float $now): void
    {
        if (!$this->cursor || $this->live === []) {
            return;
        }

        $this->lastEventAt = $now;
        $this->redraw();
    }

    #[\Override]
    public function finish(): void
    {
        if ($this->cursorHidden) {
            $this->output->write("\x1b[?25h");
            $this->cursorHidden = false;
        }

        $this->eraseLiveRegion();

        foreach ($this->problems as $problem) {
            $this->output->write(\sprintf(
                "\n%s %s\n%s",
                $this->style->fail(ProblemDetails::outcomeLabel($problem)),
                $problem->id,
                ProblemDetails::render($problem),
            ));
        }

        $finished = $this->runFinished;

        if ($finished instanceof RunFinished) {
            $this->output->write(\sprintf(
                "\n%s\nTime: %.3fs\n",
                SummaryFormat::tests($finished->summary, $this->expectations, $this->style),
                $finished->durationSeconds,
            ));
        }

        $workers = SummaryFormat::workers($this->workersSpawned, $this->workersRecycled);

        if ($workers !== null) {
            $this->output->write($workers . "\n");
        }

        $this->output->write(SummaryFormat::skipped($this->skipped, $this->style));
        $this->output->write($this->slowTests->render($this->style));

        if ($this->risky !== []) {
            $this->output->write(\sprintf(
                "\nRisky: %d passed without verifying any expectation (opt out with #[NoExpectations], enforce with --fail-on-risky):\n%s\n",
                \count($this->risky),
                \implode("\n", \array_map(static fn(string $id): string => '  ' . $id, $this->risky)),
            ));
        }
    }

    private function finalizeClass(string $class): void
    {
        $state = $this->live[$class] ?? ['done' => 0, 'failed' => 0, 'skipped' => 0, 'duration' => 0.0, 'startedAt' => 0.0];
        unset($this->live[$class]);

        $permanent = $this->verbose || !$this->cursor || $state['failed'] > 0 || $state['skipped'] > 0;

        if (!$this->cursor) {
            if ($permanent) {
                $this->output->write($this->finalLine($class, $state) . "\n");
            }

            return;
        }

        $scrollback = [];

        if ($permanent) {
            // The first permanent line opens the scrollback block with a
            // blank line so it never butts against the header; later lines
            // stack directly under it.
            if (!$this->scrollbackStarted) {
                $scrollback[] = '';
            }

            $this->scrollbackStarted = true;
            $scrollback[] = $this->finalLine($class, $state);
        }

        // A permanent line has to land now, so it rides its frame past the
        // throttle; a clean pass just waits for the next scheduled repaint.
        $this->redraw($scrollback, force: $scrollback !== []);
    }

    /**
     * @param array{done: int, failed: int, skipped: int, duration: float, startedAt: float} $state
     */
    private function finalLine(string $class, array $state): string
    {
        $counts = Style::count($state['done'], 'test');

        if ($state['failed'] > 0) {
            $counts .= \sprintf(', %d failed', $state['failed']);
        }

        if ($state['skipped'] > 0) {
            $counts .= $state['skipped'] === $state['done']
                ? ', skipped'
                : \sprintf(', %d skipped', $state['skipped']);
        }

        $mark = $state['failed'] > 0
            ? $this->style->fail('✗')
            : ($state['done'] === $state['skipped'] && $state['done'] > 0 ? $this->style->skip('−') : $this->style->pass('✓'));

        return \sprintf('%s %s (%s, %s)', $mark, $class, $counts, $this->style->duration($state['duration']));
    }

    /**
     * @param list<string> $scrollback permanent lines the frame leaves above the window
     */
    private function redraw(array $scrollback = [], bool $force = false): void
    {
        if (!$this->cursor || (!$force && $this->lastEventAt - $this->lastDrawAt < self::REDRAW_INTERVAL_SECONDS)) {
            return;
        }

        $this->lastDrawAt = $this->lastEventAt;
        $this->spinnerFrame = ($this->spinnerFrame + 1) % \count(self::SPINNER);
        // The leading blank line separates the window from the permanent
        // scrollback (header, failed or skipped class lines) above it.
        $lines = ['', $this->counterLine(self::SPINNER[$this->spinnerFrame])];

        $slots = $this->windowCapacity - 1;
        $visible = $this->live;
        $overflow = 0;

        if (\count($this->live) > $slots) {
            $visible = \array_slice($this->live, 0, $slots - 1, preserve_keys: true);
            $overflow = \count($this->live) - \count($visible);
        }

        foreach ($visible as $class => $state) {
            $mark = $state['failed'] > 0 ? $this->style->fail('✗') : ' ';
            // Dim name and count read as pending; the failure mark and the
            // duration keep their colours so failures and slow classes stay
            // visible.
            $lines[] = \sprintf(
                '%s %s %s',
                $mark,
                $this->style->dim(\sprintf('%s (%d)', $class, $state['done'])),
                $this->style->duration(\max(0.0, $this->lastEventAt - $state['startedAt'])),
            );
        }

        if ($overflow > 0) {
            $lines[] = $this->style->dim(\sprintf('  … and %d more running', $overflow));
        }

        // One frame, one write: reposition over the previous window and
        // rewrite each line in place, clearing it just before its
        // replacement lands. Blanking the whole region first and rebuilding
        // it across separate writes lets the terminal paint the blank
        // in-between state, which reads as flicker. Permanent scrollback
        // lines ride the same frame, landing where the old window began.
        $frame = $this->cursorHidden ? '' : "\x1b[?25l";
        $this->cursorHidden = true;
        $frame .= $this->drawnLines > 0 ? \sprintf("\x1b[%dA", $this->drawnLines) : '';
        $frame .= "\r";

        foreach ($scrollback as $line) {
            $frame .= "\x1b[2K" . $line . "\n";
        }

        foreach ($lines as $line) {
            $frame .= "\x1b[2K" . $line . "\n";
        }

        if ($this->drawnLines > \count($scrollback) + \count($lines)) {
            $frame .= "\x1b[0J";
        }

        $this->output->write($frame);
        $this->drawnLines = \count($lines);
    }

    private function counterLine(string $spinner): string
    {
        $line = \sprintf('%s %d/%d tests', $this->style->dim($spinner), $this->finishedTests, $this->plannedTests);

        if ($this->failedTests > 0) {
            $line .= ', ' . $this->style->fail(\sprintf('%d failed', $this->failedTests));
        }

        if ($this->skippedTests > 0) {
            $line .= ', ' . $this->style->skip(\sprintf('%d skipped', $this->skippedTests));
        }

        return $line;
    }

    private function eraseLiveRegion(): void
    {
        if (!$this->cursor || $this->drawnLines === 0) {
            return;
        }

        // Move to the start of the live region and clear to screen end.
        $this->output->write(\sprintf("\x1b[%dA\r\x1b[0J", $this->drawnLines));
        $this->drawnLines = 0;
    }
}
