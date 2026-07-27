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
 * Shows a bounded live window for active test classes. It updates the window no
 * more than one time in 50 ms.
 *
 * In bounded mode, a passed test class produces no permanent line. A class
 * with failures or skipped tests adds a line when it completes. --verbose
 * adds a permanent line for each class. Without cursor support, each class
 * adds a line.
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

    /**
     * @var list<string>
     */
    private array $successfulAttachments = [];

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
     * Returns from three to ten lines.
     *
     * The three lines are the counter, one class, and overflow. The upper
     * limit leaves space on short terminals.
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
                // The first blank line of the window gives the space in cursor
                // mode. Write the space here for append-only output.
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

            if ($result->outcome->isSuccessful() && $result->attachments !== []) {
                $this->successfulAttachments[] = '  ' . $result->id . "\n"
                    . AttachmentFormat::render($result, '    ');
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
                $this->style->error(ProblemDetails::outcomeLabel($problem)),
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

        if ($this->successfulAttachments !== []) {
            $this->output->write("\nRetained attachments from successful tests:\n");

            foreach ($this->successfulAttachments as $rendered) {
                $this->output->write($rendered);
            }
        }

        if ($this->risky !== []) {
            $this->output->write(\sprintf(
                "\nRisky tests: %d\n"
                . "These tests passed without a verified expectation.\n"
                . "Add #[NoExpectations] to accept this result. Use --fail-on-risky to fail the run.\n%s\n",
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
            // Add a blank line before the first permanent line. Thus, the
            // scrollback block does not touch the header. Add later lines
            // directly below it.
            if (!$this->scrollbackStarted) {
                $scrollback[] = '';
            }

            $this->scrollbackStarted = true;
            $scrollback[] = $this->finalLine($class, $state);
        }

        // Write a permanent line immediately, without the update limit. A
        // passed class waits for the next scheduled update.
        $this->redraw($scrollback, force: $scrollback !== []);
    }

    /**
     * @param array{done: int, failed: int, skipped: int, duration: float, startedAt: float} $state
     */
    private function finalLine(string $class, array $state): string
    {
        $counts = Plural::count($state['done'], 'test');

        if ($state['failed'] > 0) {
            $counts .= \sprintf(', %d failed', $state['failed']);
        }

        if ($state['skipped'] > 0) {
            $counts .= $state['skipped'] === $state['done']
                ? ', skipped'
                : \sprintf(', %d skipped', $state['skipped']);
        }

        $mark = $state['failed'] > 0
            ? $this->style->error('✗')
            : ($state['done'] === $state['skipped'] && $state['done'] > 0 ? $this->style->warn('−') : $this->style->ok('✓'));

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
        // The first blank line separates the window from the permanent
        // scrollback above it. The scrollback contains the header and lines
        // for failed or skipped classes.
        $lines = ['', $this->counterLine(self::SPINNER[$this->spinnerFrame])];

        $slots = $this->windowCapacity - 1;
        $visible = $this->live;
        $overflow = 0;

        if (\count($this->live) > $slots) {
            $visible = \array_slice($this->live, 0, $slots - 1, preserve_keys: true);
            $overflow = \count($this->live) - \count($visible);
        }

        foreach ($visible as $class => $state) {
            $mark = $state['failed'] > 0 ? $this->style->error('✗') : ' ';
            // Dim the name and count to show an active class. Keep the failure
            // mark and duration colors to show failures and slow classes.
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

        // Write one complete frame. Move to the previous window and replace
        // each line after its removal. Do not remove the complete area in a
        // separate write. The terminal can show that empty state as a flicker.
        // Put permanent scrollback lines in the same frame at the start of the
        // old window.
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
            $line .= ', ' . $this->style->error(\sprintf('%d failed', $this->failedTests));
        }

        if ($this->skippedTests > 0) {
            $line .= ', ' . $this->style->warn(\sprintf('%d skipped', $this->skippedTests));
        }

        return $line;
    }

    private function eraseLiveRegion(): void
    {
        if (!$this->cursor || $this->drawnLines === 0) {
            return;
        }

        // Move to the start of the live window and clear to the end of the screen.
        $this->output->write(\sprintf("\x1b[%dA\r\x1b[0J", $this->drawnLines));
        $this->drawnLines = 0;
    }
}
