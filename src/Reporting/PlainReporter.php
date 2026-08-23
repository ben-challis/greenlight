<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Event\Event;
use Greenlight\Event\RunFinished;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Event\WorkerSpawned;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;

/**
 * Produces deterministic CI logs without ANSI sequences.
 *
 * onEvent() writes one line for each completed test when its event arrives.
 * After the run, finish() writes failure and error details. It then writes a
 * final summary with worker process counts, skipped-test reasons, and retried
 * passes.
 *
 * The reporter does not use color or cursor control. Identical event streams
 * produce identical bytes. If a header is available, the reporter writes it
 * one time before the run line.
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
     * @var list<TestResult>
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
                Plural::count($event->plannedTests, 'test'),
                Plural::count($event->workers, 'worker'),
            ));

            return;
        }

        if ($event instanceof TestFinished) {
            $this->slowTests->record($event);
            $result = $event->result;
            $this->expectations = SaturatingCount::add($this->expectations, $result->expectations);
            $attempts = $result->attempts > 1
                ? ($result->outcome === Outcome::Passed
                    ? \sprintf(' (passed after %d attempts)', $result->attempts)
                    : \sprintf(' (attempts: %d)', $result->attempts))
                : '';

            $this->output->write(\sprintf(
                "%s %s (%.3fs)%s\n",
                ProblemDetails::outcomeLabel($result),
                $result->id,
                $result->durationSeconds,
                $attempts,
            ));

            if ($result->outcome->isSuccessful() && $result->attachments !== []) {
                $this->output->write(AttachmentFormat::render($result));
            }

            if (!$result->outcome->isSuccessful()) {
                $this->problems[] = $result;
            }

            if ($result->outcome === Outcome::Skipped) {
                $this->skipped[] = $result;
            }

            if ($result->outcome === Outcome::Passed && $result->attempts > 1) {
                $this->retriedPasses[] = $result;
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
                SummaryFormat::tests($finished->summary, $this->expectations, $this->style, \count($this->retriedPasses)),
                $finished->durationSeconds,
            ));
        }

        $workers = SummaryFormat::workers($this->workersSpawned);

        if ($workers !== null) {
            $this->output->write($workers . "\n");
        }

        $this->output->write(SummaryFormat::skipped($this->skipped, $this->style));
        $this->output->write(SummaryFormat::retriedPasses($this->retriedPasses, $this->style));
        $this->output->write($this->slowTests->render($this->style));

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

}
