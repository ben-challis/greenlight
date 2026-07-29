<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Orchestrator\ResourceLease;
use Greenlight\Runner\Orchestrator\SchedulingUnit;
use Greenlight\Runner\Orchestrator\WorkerHandle;

final readonly class WorkerHandleAssignmentLifecycleTest
{
    #[Test]
    public function assignmentLifecycleTransfersResetsAndClearsWorkerState(): void
    {
        $process = $this->stream();
        $stdout = $this->stream();
        $stderr = $this->stream();

        try {
            $handle = new WorkerHandle('worker-1', 1, $process, $stdout, $stderr);

            Expect::that($handle->isFresh())
                ->because('a worker MUST be fresh before its first assignment completes')
                ->toBeTrue();

            $staleTally = new ResultSummary(passed: 1);
            $staleInFlight = new TestId('Acme\\PreviousTest', 'runs');
            $handle->tally = $staleTally;
            $handle->finished = ['Acme\\PreviousTest::runs' => true];
            $handle->inFlight = $staleInFlight;
            $handle->inFlightAttempt = 2;

            $lease = $this->lease();
            $handle->beginAssignment($lease);

            Expect::that([
                'lease transferred' => $handle->lease === $lease,
                'plan transferred' => $handle->assigned === $lease->unit->plan,
                'isolation transferred' => $handle->isolatedAssignment,
                'tally replaced' => $handle->tally !== $staleTally,
                'tally reset' => $handle->tally->toWire(),
                'finished reset' => $handle->finished,
                'in-flight reset' => $handle->inFlight,
                'attempt reset' => $handle->inFlightAttempt,
                'fresh until completion' => $handle->isFresh(),
            ])
                ->because('assignment start MUST transfer the lease and reset transient worker state')
                ->toBe([
                    'lease transferred' => true,
                    'plan transferred' => true,
                    'isolation transferred' => true,
                    'tally replaced' => true,
                    'tally reset' => [
                        'passed' => 0,
                        'failed' => 0,
                        'errored' => 0,
                        'skipped' => 0,
                    ],
                    'finished reset' => [],
                    'in-flight reset' => null,
                    'attempt reset' => 0,
                    'fresh until completion' => true,
                ]);

            $handle->inFlight = $lease->unit->plan->entries[0]->id;
            $handle->inFlightAttempt = 3;
            $handle->finishAssignment();

            Expect::that([
                'lease' => $handle->lease,
                'plan' => $handle->assigned,
                'isolated' => $handle->isolatedAssignment,
                'in-flight' => $handle->inFlight,
                'attempt' => $handle->inFlightAttempt,
                'fresh' => $handle->isFresh(),
            ])
                ->because('assignment finish MUST clear assignment-scoped state and mark the worker as used')
                ->toBe([
                    'lease' => null,
                    'plan' => null,
                    'isolated' => false,
                    'in-flight' => null,
                    'attempt' => 0,
                    'fresh' => false,
                ]);
        } finally {
            $this->close($process);
            $this->close($stdout);
            $this->close($stderr);
        }
    }

    private function lease(): ResourceLease
    {
        $id = new TestId('Acme\\ExampleTest', 'runs');
        $plan = new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method)),
        ]);

        return new ResourceLease(41, new SchedulingUnit($plan, isolated: true));
    }

    /**
     * @return resource
     */
    private function stream(): mixed
    {
        $stream = \fopen('php://memory', 'r+');

        if (!\is_resource($stream)) {
            Fail::because('Expected the in-memory stream to open.');
        }

        return $stream;
    }

    private function close(mixed $stream): void
    {
        if (\is_resource($stream)) {
            \fclose($stream);
        }
    }
}
