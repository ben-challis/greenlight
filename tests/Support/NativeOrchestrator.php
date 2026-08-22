<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\ProcessPool\Orchestrator\InitialWorkerAssignment;
use Greenlight\Execution\ProcessPool\Orchestrator\NativeWorkerTransport;
use Greenlight\Execution\ProcessPool\Orchestrator\Orchestrator;
use Greenlight\Execution\ProcessPool\Orchestrator\OrchestratorConfiguration;
use Greenlight\IntegrationFixture\ProvisionedIntegrationFixtures;
use Greenlight\Internal\Process\GracefulShutdown;
use Greenlight\Reporting\Ticking;
use Greenlight\Result\ResultPolicy;

/**
 * Creates an orchestrator with the native transport for integration tests.
 */
final readonly class NativeOrchestrator
{
    /**
     * @param non-empty-list<non-empty-string> $workerCommand
     * @param array<non-empty-string, positive-int> $resourceLimits
     */
    public static function create(
        array $workerCommand,
        string $workingDirectory,
        ?int $stopAfterFailures = null,
        ?CoverageSettings $coverageSettings = null,
        ?string $configFile = null,
        bool $detectLeaks = false,
        ?ResultPolicy $policy = null,
        ?GracefulShutdown $shutdown = null,
        ?Ticking $ticker = null,
        ?ArtifactStore $artifactStore = null,
        ?ArtifactConfiguration $artifactConfiguration = null,
        ProvisionedIntegrationFixtures $integrationFixtures = new ProvisionedIntegrationFixtures(),
        float $connectDeadlineSeconds = OrchestratorConfiguration::DEFAULT_CONNECT_DEADLINE_SECONDS,
        float $progressDeadlineSeconds = OrchestratorConfiguration::DEFAULT_PROGRESS_DEADLINE_SECONDS,
        array $resourceLimits = [],
        InitialWorkerAssignment $initialWorkerAssignment = InitialWorkerAssignment::Progressive,
        ?string $generatedCodeDirectory = null,
        ?string $temporaryDirectory = null,
    ): Orchestrator {
        return new Orchestrator(
            NativeWorkerTransport::listen($workerCommand, $workingDirectory, $temporaryDirectory),
            new OrchestratorConfiguration(
                $stopAfterFailures,
                $coverageSettings,
                $configFile,
                $detectLeaks,
                $policy,
                $shutdown,
                $ticker,
                $artifactStore,
                $artifactConfiguration,
                $integrationFixtures,
                $connectDeadlineSeconds,
                $progressDeadlineSeconds,
                $resourceLimits,
                $initialWorkerAssignment,
                $generatedCodeDirectory,
                $temporaryDirectory,
            ),
        );
    }
}
