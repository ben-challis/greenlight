<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Event\Event;
use Greenlight\Event\RunFinished;
use Greenlight\Event\TestFinished;
use Greenlight\Event\WorkerSpawned;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;

/**
 * Collects the final summary of the human reporters.
 *
 * The onEvent() method records results, expectations, slow tests, worker
 * processes, and the run result. The problems() method renders failure
 * details. The totals() method renders the result counts and the time. It
 * also renders the worker count, skip reasons, retried passes, and slow tests.
 * The risky() method renders the risky-test guidance.
 *
 * @internal
 */
final class RunSummary
{
    /**
     * @var list<TestResult>
     */
    private array $problems = [];

    /**
     * @var list<TestSummary>
     */
    private array $skipped = [];

    /**
     * @var list<TestSummary>
     */
    private array $retriedPasses = [];

    /**
     * @var list<non-empty-string>
     */
    private array $risky = [];

    private int $workersSpawned = 0;

    /**
     * @var non-negative-int
     */
    private int $expectations = 0;

    private ?RunFinished $runFinished = null;

    private readonly SlowTests $slowTests;

    public function __construct(private readonly Style $style, bool $extendedSlowTests = false)
    {
        $this->slowTests = new SlowTests($extendedSlowTests);
    }

    public function onEvent(Event $event): void
    {
        if ($event instanceof TestFinished) {
            $this->slowTests->record($event);
            $result = $event->result;
            $this->expectations = SaturatingCount::add($this->expectations, $result->expectations);

            if (!$result->outcome->isSuccessful()) {
                $this->problems[] = $result;
            }

            if ($result->outcome === Outcome::Skipped) {
                $this->skipped[] = new TestSummary($result);
            }

            if ($result->outcome === Outcome::Passed && $result->attempts > 1) {
                $this->retriedPasses[] = new TestSummary($result);
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

        if ($event instanceof RunFinished) {
            $this->runFinished = $event;
        }
    }

    public function problems(): string
    {
        $rendered = '';

        foreach ($this->problems as $problem) {
            $rendered .= \sprintf(
                "\n%s %s\n%s",
                $this->style->error(ProblemDetails::outcomeLabel($problem)),
                $problem->id,
                ProblemDetails::render($problem),
            );
        }

        return $rendered;
    }

    public function totals(): string
    {
        $rendered = '';
        $finished = $this->runFinished;

        if ($finished instanceof RunFinished) {
            $rendered .= \sprintf(
                "\n%s\nTime: %.3fs\n",
                SummaryFormat::tests($finished->summary, $this->expectations, $this->style, \count($this->retriedPasses)),
                $finished->durationSeconds,
            );
        }

        $workers = SummaryFormat::workers($this->workersSpawned);

        if ($workers !== null) {
            $rendered .= $workers . "\n";
        }

        return $rendered
            . SummaryFormat::skipped($this->skipped, $this->style)
            . SummaryFormat::retriedPasses($this->retriedPasses, $this->style)
            . $this->slowTests->render($this->style);
    }

    public function risky(): string
    {
        return $this->risky === [] ? '' : SummaryFormat::risky($this->risky);
    }
}
