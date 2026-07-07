<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Result\TestResult;

/**
 * A parallel-aware live display: one line per in-flight class with a spinner
 * and a running count, finalised in place to a tick or a cross as classes
 * complete. Because workers interleave freely, the unit of progress is the
 * class, not the individual dot; the live region is redrawn on every event,
 * which is also what advances the spinner.
 *
 * Without ANSI support the live region is skipped and each class prints one
 * summary line as it finishes. A seed line is appended when the run was
 * randomized.
 *
 * @internal
 */
final class TtyReporter implements Reporter
{
    private const array SPINNER = ['|', '/', '-', '\\'];

    /**
     * @var array<string, array{done: int, failed: int, skipped: int, duration: float}>
     */
    private array $live = [];

    /**
     * @var list<TestResult>
     */
    private array $problems = [];

    private int $drawnLines = 0;

    private int $spinnerFrame = 0;

    private int $workersSpawned = 0;

    private int $workersRecycled = 0;

    private ?RunFinished $runFinished = null;

    public function __construct(
        private readonly Output\Output $output,
        private readonly bool $ansi,
        private readonly ?int $seed = null,
    ) {}

    #[\Override]
    public function onEvent(Event $event): void
    {
        if ($event instanceof TestClassStarted) {
            $this->live[$event->class] = ['done' => 0, 'failed' => 0, 'skipped' => 0, 'duration' => 0.0];
            $this->redraw();

            return;
        }

        if ($event instanceof TestFinished) {
            $result = $event->result;
            $class = $result->id->class;

            if (isset($this->live[$class])) {
                ++$this->live[$class]['done'];
                $this->live[$class]['duration'] += $result->durationSeconds;

                if (!$result->outcome->isSuccessful()) {
                    ++$this->live[$class]['failed'];
                } elseif ($result->skipReason !== null) {
                    ++$this->live[$class]['skipped'];
                }
            }

            if (!$result->outcome->isSuccessful()) {
                $this->problems[] = $result;
            }

            $this->redraw();

            return;
        }

        if ($event instanceof TestClassFinished) {
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
    public function finish(): void
    {
        $this->eraseLiveRegion();

        foreach ($this->problems as $problem) {
            $this->output->write(\sprintf(
                "\n%s %s\n%s",
                ProblemDetails::outcomeLabel($problem),
                $problem->id,
                ProblemDetails::render($problem),
            ));
        }

        $finished = $this->runFinished;

        if ($finished instanceof RunFinished) {
            $summary = $finished->summary;

            $this->output->write(\sprintf(
                "\nTests: %d, Passed: %d, Failed: %d, Errored: %d, Skipped: %d\nTime: %.3fs\n",
                $summary->total(),
                $summary->passed,
                $summary->failed,
                $summary->errored,
                $summary->skipped,
                $finished->durationSeconds,
            ));
        }

        $this->output->write(\sprintf(
            "Workers: %d spawned, %d recycled\n",
            $this->workersSpawned,
            $this->workersRecycled,
        ));

        if ($this->seed !== null) {
            $this->output->write(\sprintf("Seed: %d\n", $this->seed));
        }
    }

    private function finalizeClass(string $class): void
    {
        $state = $this->live[$class] ?? ['done' => 0, 'failed' => 0, 'skipped' => 0, 'duration' => 0.0];
        unset($this->live[$class]);

        $this->eraseLiveRegion();
        $this->output->write($this->finalLine($class, $state) . "\n");
        $this->redraw();
    }

    /**
     * @param array{done: int, failed: int, skipped: int, duration: float} $state
     */
    private function finalLine(string $class, array $state): string
    {
        $counts = \sprintf('%d tests', $state['done']);

        if ($state['failed'] > 0) {
            $counts .= \sprintf(', %d failed', $state['failed']);
        }

        if ($state['skipped'] > 0) {
            $counts .= \sprintf(', %d skipped', $state['skipped']);
        }

        $mark = $state['failed'] > 0
            ? $this->paint('✗', '31')
            : ($state['done'] === $state['skipped'] && $state['done'] > 0 ? $this->paint('−', '33') : $this->paint('✓', '32'));

        return \sprintf('%s %s (%s, %.3fs)', $mark, $class, $counts, $state['duration']);
    }

    private function redraw(): void
    {
        if (!$this->ansi) {
            return;
        }

        $this->eraseLiveRegion();
        $this->spinnerFrame = ($this->spinnerFrame + 1) % \count(self::SPINNER);
        $spinner = self::SPINNER[$this->spinnerFrame];

        foreach ($this->live as $class => $state) {
            $mark = $state['failed'] > 0 ? $this->paint('✗', '31') : $this->paint($spinner, '2');
            $this->output->write(\sprintf("%s %s (%d)\n", $mark, $class, $state['done']));
        }

        $this->drawnLines = \count($this->live);
    }

    private function eraseLiveRegion(): void
    {
        if (!$this->ansi || $this->drawnLines === 0) {
            return;
        }

        // Move to the start of the live region and clear to screen end.
        $this->output->write(\sprintf("\x1b[%dA\r\x1b[0J", $this->drawnLines));
        $this->drawnLines = 0;
    }

    private function paint(string $glyph, string $code): string
    {
        if (!$this->ansi) {
            return $glyph;
        }

        return \sprintf("\x1b[%sm%s\x1b[0m", $code, $glyph);
    }
}
