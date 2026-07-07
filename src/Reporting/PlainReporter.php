<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Result\TestResult;
use Greenlight\Reporting\Output\Output;

/**
 * Deterministic non-ANSI renderer for CI logs: one line per finished test,
 * failure and error details after the run, and a final summary including
 * worker recycling counts. No colours, no cursor control; identical event
 * streams produce byte-identical output.
 *
 * @internal
 */
final class PlainReporter implements Reporter
{
    /**
     * @var list<TestResult>
     */
    private array $problems = [];

    private int $workersSpawned = 0;

    /**
     * @var array<string, int>
     */
    private array $recycleCounts = [];

    private ?RunFinished $runFinished = null;

    private readonly SlowTests $slowTests;

    public function __construct(
        private readonly Output $output,
    ) {
        $this->slowTests = new SlowTests();
    }

    #[\Override]
    public function onEvent(Event $event): void
    {
        if ($event instanceof RunStarted) {
            $this->output->write(\sprintf(
                "Run %s: %d tests, %d workers\n\n",
                $event->runId,
                $event->plannedTests,
                $event->workers,
            ));

            return;
        }

        if ($event instanceof TestFinished) {
            $this->slowTests->record($event);
            $result = $event->result;
            $attempts = $result->attempts > 1 ? \sprintf(' (attempts: %d)', $result->attempts) : '';

            $this->output->write(\sprintf(
                "%s %s (%.3fs)%s\n",
                ProblemDetails::outcomeLabel($result),
                $result->id,
                $result->durationSeconds,
                $attempts,
            ));

            if (!$result->outcome->isSuccessful()) {
                $this->problems[] = $result;
            }

            return;
        }

        if ($event instanceof WorkerSpawned) {
            ++$this->workersSpawned;

            return;
        }

        if ($event instanceof WorkerRecycled) {
            $reason = $event->reason->value;
            $this->recycleCounts[$reason] = ($this->recycleCounts[$reason] ?? 0) + 1;

            return;
        }

        if ($event instanceof RunFinished) {
            $this->runFinished = $event;
        }
    }

    #[\Override]
    public function finish(): void
    {
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
            "Workers: %d spawned, %d recycled%s\n",
            $this->workersSpawned,
            \array_sum($this->recycleCounts),
            $this->recycleBreakdown(),
        ));

        $this->output->write($this->slowTests->render());
    }

    private function recycleBreakdown(): string
    {
        $parts = [];

        foreach (RecycleReason::cases() as $reason) {
            $count = $this->recycleCounts[$reason->value] ?? 0;

            if ($count > 0) {
                $parts[] = \sprintf('%s: %d', $reason->value, $count);
            }
        }

        if ($parts === []) {
            return '';
        }

        return ' (' . \implode(', ', $parts) . ')';
    }
}
