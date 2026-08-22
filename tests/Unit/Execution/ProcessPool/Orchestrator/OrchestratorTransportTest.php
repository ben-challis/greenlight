<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Event\TestFinished;
use Greenlight\Event\TestStarted;
use Greenlight\Execution\ProcessPool\Orchestrator\Orchestrator;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Done;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Ready;
use Greenlight\Expect\Expect;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Test\ExecutionPolicy;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\ScriptedWorkerTransport;

final readonly class OrchestratorTransportTest
{
    #[Test]
    public function scriptedTransportCompletesTheWorkerLifecycleWithoutNativeResources(): void
    {
        $id = new TestId('Example\ScriptedTest', 'passes');
        $transport = new ScriptedWorkerTransport([[
            new Ready(),
            new EventEnvelope(new TestStarted($id, 1.0)),
            new EventEnvelope(new TestFinished(
                new TestResult($id, Outcome::Passed, 0.01, 1),
                1.01,
            )),
            new Done(new ResultSummary(passed: 1), 1_024),
        ]]);
        $orchestrator = new Orchestrator($transport);

        $summary = $orchestrator->run($this->plan($id), new CollectingEventSink(), 1);

        Expect::that($summary->passed)
            ->because('the scripted worker MUST complete its assignment')
            ->toBe(1);
        Expect::that($transport->started)
            ->because('the orchestrator MUST allocate one transport worker and one channel')
            ->toBe([['workerId' => 'w-1', 'channel' => 1]]);
        Expect::that(\array_map(
            static fn(array $sent): string => $sent['message']::tag(),
            $transport->sent,
        ))
            ->because('the transport MUST observe orchestration protocol decisions in order')
            ->toBe(['bootstrap', 'assign', 'drain']);
        Expect::that($orchestrator->workerTimings())
            ->because('transport retirement MUST produce one completed worker timing record')
            ->toHaveCount(1);
        Expect::that($transport->isClosed())
            ->because('the orchestrator MUST close its transport after the run')
            ->toBeTrue();
    }

    #[Test]
    public function scriptedTimeLetsTheOrchestratorContainATestTimeout(): void
    {
        $id = new TestId('Example\ScriptedTest', 'timesOut');
        $transport = new ScriptedWorkerTransport([[
            new Ready(),
            new EventEnvelope(new TestStarted($id, 1.0)),
        ]], pollSeconds: 3.0);
        $orchestrator = new Orchestrator($transport);
        $sink = new CollectingEventSink();

        $summary = $orchestrator->run($this->plan($id, timeoutSeconds: 0.1), $sink, 1);
        $results = $sink->results();

        Expect::that($summary->failed)
            ->because('orchestration policy MUST contain the timed-out test')
            ->toBe(1);
        Expect::that($results)->toHaveCount(1);
        Expect::that($results[0]->failures[0]->message ?? '')
            ->because('the synthetic result MUST identify the configured time limit')
            ->toContain('exceeded its 0.100-second time limit');
        Expect::that($orchestrator->workerTimings())
            ->because('forced transport retirement MUST complete before the run returns')
            ->toHaveCount(1);
    }

    private function plan(TestId $id, ?float $timeoutSeconds = null): ExecutionPlan
    {
        return new ExecutionPlan([
            new PlanEntry(new TestDefinition(
                $id->class,
                $id->method,
                execution: new ExecutionPolicy(timeoutSeconds: $timeoutSeconds),
            )),
        ]);
    }
}
