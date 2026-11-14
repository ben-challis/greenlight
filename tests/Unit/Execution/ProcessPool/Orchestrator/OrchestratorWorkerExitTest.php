<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Event\TestFinished;
use Greenlight\Event\TestStarted;
use Greenlight\Execution\ProcessPool\Orchestrator\Orchestrator;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Assign;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Bootstrap;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\PlanEntryFixture;
use Greenlight\Tests\Support\ReactiveWorkerTransport;

use function Greenlight\expect;

final readonly class OrchestratorWorkerExitTest
{
    #[Test]
    public function workersThatExitDuringBootstrapStopTheRunWithDiagnostics(): void
    {
        $transport = new ReactiveWorkerTransport(
            static function (ReactiveWorkerTransport $transport, string $workerId): void {
                $transport->hello($workerId);
                $transport->setDiagnostics($workerId, 'bootstrap.php called exit()');
            },
            static function (ReactiveWorkerTransport $transport, string $workerId, Message $message): void {
                if ($message instanceof Bootstrap) {
                    $transport->disconnect($workerId);
                }
            },
        );
        $orchestrator = new Orchestrator($transport);

        expect()->calling(fn(): ResultSummary => $orchestrator->run($this->plan(400), new CollectingEventSink(), 2))
            ->because('consecutive worker exits without a finished test MUST stop the run with the worker output')
            ->toThrow(
                ProtocolError::class,
                '/^3 workers in sequence exited before they finished a test\. Last worker "w-3": '
                . 'the worker process exited unexpectedly\..*\nWorker output:\nbootstrap\.php called exit\(\)$/s',
            );
        expect($transport->started)
            ->because('the run MUST stop before it starts a worker for each planned test')
            ->toHaveCount(4);
    }

    #[Test]
    public function aFinishedTestResetsTheWorkerExitCount(): void
    {
        $transport = new ReactiveWorkerTransport(
            ReactiveWorkerTransport::connectAtStart(),
            ReactiveWorkerTransport::passingWorkers(
                static function (ReactiveWorkerTransport $transport, string $workerId, Assign $assign): bool {
                    // Each worker finishes one test and exits between tests.
                    $first = $assign->slice->entries[0]->id;
                    $transport->message($workerId, new EventEnvelope(new TestStarted($first, 1.0)));
                    $transport->message($workerId, new EventEnvelope(new TestFinished(
                        new TestResult($first, Outcome::Passed, 0.01, 0),
                        1.01,
                    )));
                    $transport->disconnect($workerId);

                    return true;
                },
            ),
        );

        $summary = new Orchestrator($transport)->run(
            new ExecutionPlan(\array_map(
                static fn(int $index) => PlanEntryFixture::create('Example\\OneClassTest', 'test' . $index),
                \range(1, 6),
            )),
            new CollectingEventSink(),
            1,
        );

        expect($summary->passed)
            ->because('workers that finish tests MUST NOT use the exit limit')
            ->toBe(6);
        expect($transport->started)->toHaveCount(6);
    }

    /**
     * @param positive-int $classes
     */
    private function plan(int $classes): ExecutionPlan
    {
        return new ExecutionPlan(\array_map(
            static fn(int $index) => PlanEntryFixture::create('Example\\Class' . $index . 'Test'),
            \range(1, $classes),
        ));
    }
}
