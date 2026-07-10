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
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Reporting\Output\Output;

/**
 * Deterministic non-ANSI renderer for CI logs.
 *
 * onEvent() writes one line per finished test as it arrives. finish() prints
 * failure and error details after the run, then a final summary including
 * worker recycling counts and the skipped tests with their reasons.
 *
 * No colours, no cursor control; identical event streams produce
 * byte-identical output. When a header is provided it prints once, before
 * the run line.
 *
 * @internal
 */
final class PlainReporter implements Reporter
{
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

    private int $workersSpawned = 0;

    /**
     * @var non-negative-int
     */
    private int $expectations = 0;

    /**
     * @var array<string, int>
     */
    private array $recycleCounts = [];

    private ?RunFinished $runFinished = null;

    private readonly Style $style;

    private readonly SlowTests $slowTests;

    public function __construct(
        private readonly Output $output,
        private readonly ?RunHeader $header = null,
        bool $extendedSlowTests = false,
    ) {
        $this->style = new Style(ansi: false);
        $this->slowTests = new SlowTests($extendedSlowTests);
    }

    #[\Override]
    public function onEvent(Event $event): void
    {
        if ($event instanceof RunStarted) {
            if ($this->header instanceof RunHeader) {
                $this->output->write($this->header->render($event->workers, $this->style) . "\n");
            }

            $this->output->write(\sprintf(
                "Run %s: %s, %s\n\n",
                $event->runId,
                Style::count($event->plannedTests, 'test'),
                Style::count($event->workers, 'worker'),
            ));

            return;
        }

        if ($event instanceof TestFinished) {
            $this->slowTests->record($event);
            $result = $event->result;
            $this->expectations += $result->expectations;
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

            if ($result->outcome === Outcome::Skipped) {
                $this->skipped[] = $result;
            }

            if ($result->risky && $result->outcome->isSuccessful() && ($id = (string) $result->id) !== '') {
                $this->risky[] = $id;
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
            $this->output->write(\sprintf(
                "\n%s\nTime: %.3fs\n",
                SummaryFormat::tests($finished->summary, $this->expectations, $this->style),
                $finished->durationSeconds,
            ));
        }

        $workers = SummaryFormat::workers(
            $this->workersSpawned,
            \array_sum($this->recycleCounts),
            $this->recycleBreakdown(),
        );

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
