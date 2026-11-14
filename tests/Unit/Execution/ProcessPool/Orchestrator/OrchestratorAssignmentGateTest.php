<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Event\TestStarted;
use Greenlight\Execution\ProcessPool\Orchestrator\InitialWorkerAssignment;
use Greenlight\Execution\ProcessPool\Orchestrator\Orchestrator;
use Greenlight\Execution\ProcessPool\Orchestrator\OrchestratorConfiguration;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Assign;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Bootstrap;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Drain;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Ready;
use Greenlight\Internal\Process\GracefulShutdown;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\PlanEntryFixture;
use Greenlight\Tests\Support\ReactiveWorkerTransport;

use function Greenlight\expect;

final readonly class OrchestratorAssignmentGateTest
{
    #[Test]
    public function aShutdownBeforeTheLastInitialHelloDrainsTheLateWorker(): void
    {
        $shutdown = new GracefulShutdown();
        $transport = new ReactiveWorkerTransport(
            static function (ReactiveWorkerTransport $transport, string $workerId): void {
                if ($workerId === 'w-1') {
                    $transport->hello($workerId);
                }
            },
            static function (ReactiveWorkerTransport $transport, string $workerId, Message $message) use ($shutdown): void {
                if ($message instanceof Bootstrap) {
                    $transport->message($workerId, new Ready());

                    if ($workerId === 'w-1') {
                        // The signal arrives after the first hello and before the second.
                        $shutdown->request(\SIGINT);
                        $transport->hello('w-2');
                    }
                } elseif ($message instanceof Drain) {
                    $transport->disconnect($workerId);
                }
            },
            pollLimit: 500,
        );
        $orchestrator = new Orchestrator($transport, new OrchestratorConfiguration(
            shutdown: $shutdown,
            initialWorkerAssignment: InitialWorkerAssignment::AfterAllReady,
        ));

        $summary = $orchestrator->run($this->plan(2), new CollectingEventSink(), 2);

        expect($summary->total())
            ->because('a shutdown before the barrier opens MUST NOT start tests')
            ->toBe(0);
        expect($transport->log)
            ->because('the worker that becomes ready during the drain MUST receive a drain')
            ->toContain('w-2 drain');
    }

    #[Test]
    public function aReplacementWorkerDoesNotCloseTheInitialBarrier(): void
    {
        $transport = new ReactiveWorkerTransport(
            static function (ReactiveWorkerTransport $transport, string $workerId): void {
                if ($workerId === 'w-3') {
                    // The replacement worker has a slow bootstrap.
                    $transport->after(20, static fn(ReactiveWorkerTransport $transport) => $transport->hello($workerId));

                    return;
                }

                $transport->hello($workerId);
            },
            ReactiveWorkerTransport::passingWorkers(
                static function (ReactiveWorkerTransport $transport, string $workerId, Assign $assign): bool {
                    if ($workerId !== 'w-1') {
                        return false;
                    }

                    // The first worker crashes during its first test.
                    $transport->message($workerId, new EventEnvelope(new TestStarted($assign->slice->entries[0]->id, 1.0)));
                    $transport->disconnect($workerId);

                    return true;
                },
            ),
        );

        $summary = new Orchestrator($transport)->run($this->plan(8), new CollectingEventSink(), 2);
        $secondWorkerAssignments = \array_keys($transport->log, 'w-2 assign', true);

        expect($summary->errored)->toBe(1);
        expect($summary->passed)->toBe(7);
        expect(\end($secondWorkerAssignments))
            ->because('a worker that finished an assignment MUST NOT wait for the bootstrap of a replacement worker')
            ->toBeLessThan($transport->logIndex('w-3 bootstrap'));
    }

    #[Test]
    public function idleWorkersAskTheBlockedQueueOnceForEachRelease(): void
    {
        $blockedUnits = 200;
        $fewWorkers = $this->resourceWaitClockReads($blockedUnits, 2);
        $manyWorkers = $this->resourceWaitClockReads($blockedUnits, 8);

        // Each extra worker adds a fixed number of lifecycle clock reads. A
        // queue scan for each idle worker on each release adds a clock read
        // for each blocked unit and idle worker.
        expect($manyWorkers - $fewWorkers)
            ->because('a release MUST NOT scan the blocked queue again for each idle worker')
            ->toBeLessThan($blockedUnits);
    }

    /**
     * @param positive-int $blockedUnits
     * @param positive-int $workers
     */
    private function resourceWaitClockReads(int $blockedUnits, int $workers): int
    {
        $entries = [];

        for ($index = 1; $index <= $blockedUnits; ++$index) {
            $entries[] = PlanEntryFixture::create('Example\\Database' . $index . 'Test', resources: ['database']);
        }

        for ($index = 1; $index < $workers; ++$index) {
            $entries[] = PlanEntryFixture::create('Example\\Free' . $index . 'Test');
        }

        $transport = new ReactiveWorkerTransport(
            ReactiveWorkerTransport::connectAtStart(),
            ReactiveWorkerTransport::passingWorkers(),
        );
        $summary = new Orchestrator($transport, new OrchestratorConfiguration(resourceLimits: ['database' => 1]))
            ->run(new ExecutionPlan($entries), new CollectingEventSink(), $workers);

        expect($summary->passed)->toBe(\count($entries));
        expect($transport->started)->toHaveCount($workers);

        return $transport->nowCalls;
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
