<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Event\TestStarted;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Artifact\TestArtifactBudget;
use Greenlight\Execution\ProcessPool\Orchestrator\Orchestrator;
use Greenlight\Execution\ProcessPool\Orchestrator\OrchestratorConfiguration;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Assign;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\ExecutionPolicy;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\ReactiveWorkerTransport;

use function Greenlight\expect;

final readonly class OrchestratorTimeoutRecoveryTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function aTimedOutWorkerIsKilledBeforeItsEvidenceIsRecovered(): void
    {
        $root = $this->tempDirectory->subdirectory('timeout-recovery');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-timeout-recovery');
        $this->cleanup->defer($store->cleanup(...));
        $id = new TestId('Example\\SlowTest', 'writesEvidenceUntilKilled');
        $transport = new ReactiveWorkerTransport(
            ReactiveWorkerTransport::connectAtStart(),
            ReactiveWorkerTransport::passingWorkers(
                static function (ReactiveWorkerTransport $transport, string $workerId, Assign $assign) use ($id): bool {
                    $transport->message($workerId, new EventEnvelope(new TestStarted($id, 1.0)));

                    return true;
                },
            ),
            static function (ReactiveWorkerTransport $transport, string $workerId, bool $force) use ($store, $id): void {
                // The worker completes this attachment before the kill signal stops it.
                $store->forAttempt($id, 1, new TestArtifactBudget())->text('last-words.txt', 'written before the kill');
            },
            pollSeconds: 0.5,
        );
        $sink = new CollectingEventSink();

        new Orchestrator($transport, new OrchestratorConfiguration(artifactStore: $store))->run(
            new ExecutionPlan([new PlanEntry(new TestDefinition(
                $id->class,
                $id->method,
                execution: new ExecutionPolicy(timeoutSeconds: 0.1),
            ))]),
            $sink,
            1,
        );
        $results = $sink->results();

        expect($transport->log)
            ->because('the orchestrator MUST kill the timed-out worker')
            ->toContain('w-1 kill');
        expect($results)->toHaveCount(1);
        expect(\array_map(static fn($attachment): string => $attachment->name, $results[0]->attachments))
            ->because('recovery after the kill MUST include evidence that the worker completed before it stopped')
            ->toBe(['last-words.txt']);
    }
}
