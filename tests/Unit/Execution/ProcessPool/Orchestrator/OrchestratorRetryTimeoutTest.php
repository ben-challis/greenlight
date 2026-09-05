<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Event\TestFinished;
use Greenlight\Event\TestStarted;
use Greenlight\Execution\ProcessPool\Orchestrator\Orchestrator;
use Greenlight\Execution\ProcessPool\Protocol\Messages\AttemptStarted;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Done;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Ready;
use Greenlight\Expect\Expect;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Test\ExecutionPolicy;
use Greenlight\Test\RetryPolicy;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\ScriptedWorkerTransport;

final readonly class OrchestratorRetryTimeoutTest
{
    #[Test]
    public function eachRetryGetsANewHardTimeoutDeadline(): void
    {
        $id = new TestId('Example\RetryTest', 'passes');
        $messages = [new Ready(), new EventEnvelope(new TestStarted($id, 1.0))];

        for ($attempt = 1; $attempt <= 40; ++$attempt) {
            $messages[] = new AttemptStarted($id, $attempt);
        }

        $messages[] = new EventEnvelope(new TestFinished(
            new TestResult($id, Outcome::Passed, 0.08, 0, attempts: 40),
            4.28,
        ));
        $messages[] = new Done(new ResultSummary(passed: 1), 1_024);
        $transport = new ScriptedWorkerTransport([$messages], pollSeconds: 0.08);
        $sink = new CollectingEventSink();

        $summary = new Orchestrator($transport)->run($this->plan($id), $sink, 1);

        Expect::that($summary->passed)->toBe(1);
        Expect::that($summary->failed)->toBe(0);
        Expect::that($sink->results()[0]->attempts)->toBe(40);
        Expect::that($transport->started)->toHaveCount(1);
    }

    #[Test]
    public function aBlockedRetryStillReachesItsHardTimeout(): void
    {
        $id = new TestId('Example\RetryTest', 'blocks');
        $transport = new ScriptedWorkerTransport([[
            new Ready(),
            new EventEnvelope(new TestStarted($id, 1.0)),
            new AttemptStarted($id, 1),
            new AttemptStarted($id, 2),
        ]], pollSeconds: 0.08);
        $sink = new CollectingEventSink();

        $summary = new Orchestrator($transport)->run($this->plan($id), $sink, 1);

        Expect::that($summary->failed)->toBe(1);
        Expect::that($sink->results()[0]->attempts)->toBe(2);
        Expect::that($sink->results()[0]->failures[0]->message)->toContain('exceeded its 0.100-second time limit');
        Expect::that($sink->results()[0]->durationSeconds)->toBeGreaterThan(2.3);
        Expect::that($sink->results()[0]->failures[0]->message)->toContain('after 2.400 seconds');
    }

    private function plan(TestId $id): ExecutionPlan
    {
        return new ExecutionPlan([new PlanEntry(new TestDefinition(
            $id->class,
            $id->method,
            retry: new RetryPolicy(39),
            execution: new ExecutionPolicy(timeoutSeconds: 0.1),
        ))]);
    }
}
