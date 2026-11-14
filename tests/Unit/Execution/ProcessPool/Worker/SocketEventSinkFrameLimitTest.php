<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Event\TestFinished;
use Greenlight\Execution\ProcessPool\Protocol\JsonFrameCodec;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Execution\ProcessPool\Worker\SocketEventSink;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\ConnectedStreamPair;

use function Greenlight\expect;

final readonly class SocketEventSinkFrameLimitTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function aResultLargerThanTheFrameLimitBecomesAnErroredResultForTheSameTest(): void
    {
        [$senderStream, $receiverStream] = ConnectedStreamPair::open();
        $sender = new SocketChannel($senderStream, new JsonFrameCodec(4_096));
        $receiver = new SocketChannel($receiverStream);
        $this->cleanup->defer($receiver->close(...));
        $this->cleanup->defer($sender->close(...));
        $sink = new SocketEventSink($sender);
        $id = new TestId('Example\\NoisyTest', 'printsALot');

        $sink->emit(new TestFinished(
            new TestResult($id, Outcome::Passed, 0.5, 0, attempts: 2, output: new CapturedOutput(\str_repeat('x', 10_000))),
            3.0,
        ));
        $message = $receiver->poll();
        $result = $message instanceof EventEnvelope && $message->event instanceof TestFinished
            ? $message->event->result
            : null;

        expect($result?->id)
            ->because('the replacement result MUST name the same test')
            ->toEqual($id);
        expect($result?->outcome)->toBe(Outcome::Errored);
        expect($result?->attempts)->toBe(2);
        expect($result?->error?->class)->toBe(ProtocolError::class);
        expect($result?->error?->message)
            ->toMatch(
                '/^Greenlight could not send the passed result of this test to the orchestrator\. '
                . 'Frame of \d+ bytes exceeds the 4096 byte limit\. '
                . 'Reduce the size of the test output, failure messages, or attachment metadata\.$/',
            );
        expect($sink->correctSummary(new ResultSummary(passed: 3, failed: 1)))
            ->because('the worker summary MUST agree with the sent results')
            ->toEqual(new ResultSummary(passed: 2, failed: 1, errored: 1));
    }

    #[Test]
    public function aSummaryWithoutReplacedResultsIsUnchanged(): void
    {
        [$senderStream, $receiverStream] = ConnectedStreamPair::open();
        $sender = new SocketChannel($senderStream);
        $this->cleanup->defer($sender->close(...));
        $this->cleanup->defer(static fn() => \fclose($receiverStream));
        $summary = new ResultSummary(passed: 3);

        expect(new SocketEventSink($sender)->correctSummary($summary))->toBe($summary);
    }
}
