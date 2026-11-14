<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Worker;

use Greenlight\Event\Event;
use Greenlight\Event\EventSink;
use Greenlight\Event\TestFinished;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;

/**
 * Sends worker events to the orchestrator.
 *
 * A test result can be larger than the frame limit. emit() then sends an
 * errored result for the same test. Thus, the run continues and the report
 * names the test. correctSummary() applies the same change to the worker
 * summary. Thus, the orchestrator summary check agrees with the sent events.
 *
 * @internal
 */
final class SocketEventSink implements EventSink
{
    /**
     * @var list<Outcome> original outcomes of the results that emit() replaced
     */
    private array $replacedOutcomes = [];

    public function __construct(private readonly SocketChannel $channel) {}

    /**
     * @throws ProtocolError
     */
    #[\Override]
    public function emit(Event $event): void
    {
        try {
            $this->channel->send(new EventEnvelope($event));
        } catch (ProtocolError $error) {
            if (!$event instanceof TestFinished || !$error->isFrameTooLarge()) {
                throw $error;
            }

            $this->channel->send(new EventEnvelope(new TestFinished(
                $this->reducedResult($event->result, $error),
                $event->occurredAt,
            )));
            $this->replacedOutcomes[] = $event->result->outcome;
        }
    }

    /**
     * Applies the outcome changes of replaced results to a worker summary.
     */
    public function correctSummary(ResultSummary $summary): ResultSummary
    {
        if ($this->replacedOutcomes === []) {
            return $summary;
        }

        $counts = [
            Outcome::Passed->name => $summary->passed,
            Outcome::Failed->name => $summary->failed,
            Outcome::Errored->name => $summary->errored,
            Outcome::Skipped->name => $summary->skipped,
        ];

        foreach ($this->replacedOutcomes as $outcome) {
            $counts[$outcome->name] = \max(0, $counts[$outcome->name] - 1);
            ++$counts[Outcome::Errored->name];
        }

        return new ResultSummary(
            $counts[Outcome::Passed->name],
            $counts[Outcome::Failed->name],
            $counts[Outcome::Errored->name],
            $counts[Outcome::Skipped->name],
        );
    }

    private function reducedResult(TestResult $result, ProtocolError $error): TestResult
    {
        return new TestResult(
            $result->id,
            Outcome::Errored,
            $result->durationSeconds,
            $result->memoryDeltaBytes,
            $result->attempts,
            error: new ThrowableDetail(
                ProtocolError::class,
                \sprintf(
                    'Greenlight could not send the %s result of this test to the orchestrator. %s '
                    . 'Reduce the size of the test output, failure messages, or attachment metadata.',
                    $result->outcome->value,
                    $error->getMessage(),
                ),
                $error->getFile(),
                \max(1, $error->getLine()),
            ),
        );
    }
}
