<?php

declare(strict_types=1);

namespace Greenlight\Execution\Adapter;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Config\WorkerConfiguration;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Event\EventSink;
use Greenlight\Execution\ExecutionAdapter;
use Greenlight\Execution\ExecutionContext;
use Greenlight\Execution\ExecutionFailed;
use Greenlight\Execution\ExecutionOutcome;
use Greenlight\Execution\ExecutionTopology;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Execution\ProcessPool\Orchestrator\Distributor;
use Greenlight\Execution\ProcessPool\Orchestrator\InitialWorkerAssignment;
use Greenlight\Execution\ProcessPool\Orchestrator\NativeWorkerTransport;
use Greenlight\Execution\ProcessPool\Orchestrator\Orchestrator;
use Greenlight\Execution\ProcessPool\Orchestrator\OrchestratorConfiguration;
use Greenlight\Execution\ProcessPool\Orchestrator\ResourceScheduler;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTransport;
use Greenlight\Internal\Process\EmergencyCleanups;
use Greenlight\Internal\Process\GracefulShutdown;
use Greenlight\Internal\Wire\WireCommunicationFailed;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Reporting\Ticking;

/**
 * Executes a plan with the orchestrator process pool.
 *
 * @internal
 */
final readonly class ProcessPoolExecution implements ExecutionAdapter
{
    /**
     * @param non-empty-list<non-empty-string> $workerCommand Command prefix that invokes bin/greenlight.
     * @param positive-int $workerCount
     */
    public function __construct(
        private array $workerCommand,
        private string $workingDirectory,
        private int $workerCount,
        private WorkerConfiguration $workers,
        private ?CoverageSettings $coverageSettings = null,
        private ?string $configFile = null,
        private bool $detectLeaks = false,
        private ?GracefulShutdown $shutdown = null,
        private ?Ticking $ticker = null,
        private ?WorkerTransport $transport = null,
        private ?EmergencyCleanups $emergencyCleanups = null,
    ) {
        if ($workerCount < 1) {
            throw new \InvalidArgumentException('Process-pool execution requires at least one worker.');
        }
    }

    #[\Override]
    public function topology(
        ExecutionPlan $plan,
        array $classSeconds,
    ): ExecutionTopology {
        [$pooled, $isolated] = new Distributor()->units($plan, $classSeconds, $this->workerCount);
        $fixtureChannels = new ResourceScheduler(
            $pooled,
            $isolated,
            $this->workers->resourceLimits,
        )->initialWorkerTarget($this->workerCount);

        return new ExecutionTopology($this->workerCount, $fixtureChannels);
    }

    /**
     * @throws AttachmentError
     * @throws ExecutionFailed
     * @throws ReportGenerationFailed
     */
    #[\Override]
    public function execute(
        ExecutionPlan $plan,
        EventSink $sink,
        ExecutionContext $context,
    ): ExecutionOutcome {
        $cleanup = null;

        try {
            $execution = $context->execution;
            $transport = $this->transport ?? NativeWorkerTransport::listen(
                $this->workerCommand,
                $this->workingDirectory,
                $context->storage->temporaryDirectory,
            );
            // Workers run in their own process groups, so the terminal does
            // not signal them. A forced exit closes the transport, which
            // kills each worker process.
            $cleanup = $this->emergencyCleanups?->register($transport->close(...));
            $orchestrator = new Orchestrator(
                $transport,
                new OrchestratorConfiguration(
                    stopAfterFailures: $execution->stopAfterFailures,
                    coverageSettings: $this->coverageSettings,
                    configFile: $this->configFile,
                    detectLeaks: $this->detectLeaks,
                    policy: $execution->policy->isNoOp() ? null : $execution->policy,
                    shutdown: $this->shutdown,
                    ticker: $this->ticker,
                    artifactStore: $context->artifacts,
                    artifactConfiguration: $execution->artifacts,
                    integrationFixtures: $context->fixtures,
                    resourceLimits: $this->workers->resourceLimits,
                    initialWorkerAssignment: WorkerPluginRuntime::requiresInitialBootstrapBarrier($execution->plugins)
                        ? InitialWorkerAssignment::AfterAllReady
                        : InitialWorkerAssignment::Progressive,
                    generatedCodeDirectory: $context->storage->generatedCodeDirectory,
                    temporaryDirectory: $context->storage->temporaryDirectory,
                ),
            );

            $summary = $orchestrator->run($plan, $sink, $this->workerCount, $context->classSeconds);
        } catch (WireCommunicationFailed $failure) {
            throw ExecutionFailed::processPool($failure);
        } finally {
            if ($cleanup !== null) {
                $this->emergencyCleanups?->unregister($cleanup);
            }
        }

        return new ExecutionOutcome(
            $summary,
            $orchestrator->collectedCoverage(),
            $orchestrator->detectedLeaks(),
            $orchestrator->workerTimings(),
        );
    }
}
