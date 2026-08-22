<?php

declare(strict_types=1);

namespace Greenlight\Runner\Execution;

use Greenlight\Config\Configuration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\GracefulShutdown;
use Greenlight\Core\Wire\WireCommunicationFailed;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Reporting\Ticking;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\Orchestrator\Distributor;
use Greenlight\Runner\Orchestrator\InitialWorkerAssignment;
use Greenlight\Runner\Orchestrator\Orchestrator;
use Greenlight\Runner\Orchestrator\ResourceScheduler;
use Greenlight\Runner\Worker\EventSink;

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
        private ?CoverageSettings $coverageSettings = null,
        private ?string $configFile = null,
        private bool $detectLeaks = false,
        private ?GracefulShutdown $shutdown = null,
        private ?Ticking $ticker = null,
    ) {
        if ($workerCount < 1) {
            throw new \InvalidArgumentException('Process-pool execution requires at least one worker.');
        }
    }

    #[\Override]
    public function topology(
        ExecutionPlan $plan,
        Configuration $configuration,
        array $classSeconds,
    ): ExecutionTopology {
        [$pooled, $isolated] = new Distributor()->units($plan, $classSeconds, $this->workerCount);
        $fixtureChannels = new ResourceScheduler(
            $pooled,
            $isolated,
            $configuration->resourceLimits,
        )->initialWorkerTarget($this->workerCount);

        return new ExecutionTopology($this->workerCount, $fixtureChannels);
    }

    /**
     * @throws AttachmentError
     * @throws ReportGenerationFailed
     * @throws WireCommunicationFailed
     */
    #[\Override]
    public function execute(
        ExecutionPlan $plan,
        EventSink $sink,
        ExecutionContext $context,
    ): ExecutionOutcome {
        $configuration = $context->configuration;
        $orchestrator = new Orchestrator(
            $this->workerCommand,
            $this->workingDirectory,
            $configuration->recycleAfterTests,
            $configuration->recycleAboveMemoryBytes,
            $configuration->stopAfterFailures,
            $this->coverageSettings,
            $this->configFile,
            $this->detectLeaks,
            $configuration->policy->isNoOp() ? null : $configuration->policy,
            $this->shutdown,
            $this->ticker,
            $context->artifacts,
            $configuration->artifacts,
            $context->fixtures,
            resourceLimits: $configuration->resourceLimits,
            initialWorkerAssignment: $context->orchestratorPlugins->hasWorkerBootstrapSubscribers()
                ? InitialWorkerAssignment::AfterAllReady
                : InitialWorkerAssignment::Progressive,
            generatedCodeDirectory: $context->storage->generatedCodeDirectory,
            temporaryDirectory: $context->storage->temporaryDirectory,
        );

        $summary = $orchestrator->run($plan, $sink, $this->workerCount, $context->classSeconds);

        return new ExecutionOutcome(
            $summary,
            $orchestrator->collectedCoverage(),
            $orchestrator->detectedLeaks(),
            $orchestrator->workerTimings(),
        );
    }
}
