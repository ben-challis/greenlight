<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Event\Event;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Result\Outcome;

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
    private readonly Style $style;

    private readonly RunSummary $summary;

    /** Test lines that wait for the next write. */
    private string $pending = '';

    private int|float $pendingSince = 0;

    public function __construct(
        private readonly Output $output,
        private readonly ?RunHeader $header = null,
        bool $extendedSlowTests = false,
    ) {
        $this->style = new Style(ansi: false);
        $this->summary = new RunSummary($this->style, $extendedSlowTests);
    }

    #[\Override]
    public function onEvent(Event $event): void
    {
        $this->summary->onEvent($event);

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
            $result = $event->result;
            $attempts = $result->attempts > 1
                ? ($result->outcome === Outcome::Passed
                    ? \sprintf(' (passed after %d attempts)', $result->attempts)
                    : \sprintf(' (attempts: %d)', $result->attempts))
                : '';

            $line = \sprintf(
                "%s %s (%.3fs)%s\n",
                ProblemDetails::outcomeLabel($result),
                $result->id,
                $result->durationSeconds,
                $attempts,
            );

            if ($result->outcome->isSuccessful() && $result->attachments !== []) {
                $line .= AttachmentFormat::render($result);
            }

            $this->buffer($line);
        }
    }

    #[\Override]
    public function finish(): void
    {
        $this->flush();
        $this->output->write($this->summary->problems() . $this->summary->totals() . $this->summary->risky());
    }

    /**
     * Holds test lines for a maximum of 50 milliseconds or 8 KiB, then writes
     * them in one operation.
     *
     * @throws ReportGenerationFailed
     */
    private function buffer(string $text): void
    {
        $now = \hrtime(true);

        if ($this->pending === '') {
            $this->pendingSince = $now;
        }

        $this->pending .= $text;

        if (\strlen($this->pending) >= 8192 || $now - $this->pendingSince >= 50_000_000) {
            $this->flush();
        }
    }

    /**
     * @throws ReportGenerationFailed
     */
    private function flush(): void
    {
        if ($this->pending === '') {
            return;
        }

        $text = $this->pending;
        $this->pending = '';
        $this->output->write($text);
    }
}
