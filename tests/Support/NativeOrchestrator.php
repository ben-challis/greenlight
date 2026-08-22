<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\GracefulShutdown;
use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Reporting\Ticking;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\Integration\ProvisionedIntegrationFixtures;
use Greenlight\Runner\Orchestrator\InitialWorkerAssignment;
use Greenlight\Runner\Orchestrator\NativeWorkerTransport;
use Greenlight\Runner\Orchestrator\Orchestrator;
use Greenlight\Runner\Orchestrator\OrchestratorConfiguration;

/**
 * Creates an orchestrator with the native transport for integration tests.
 */
final readonly class NativeOrchestrator
{
    /**
     * @param non-empty-list<non-empty-string> $workerCommand
     * @param positive-int|null $recycleAfterTests
     * @param positive-int|null $recycleAboveMemoryBytes
     * @param array<non-empty-string, positive-int> $resourceLimits
     */
    public static function create(
        array $workerCommand,
        string $workingDirectory,
        ?int $recycleAfterTests = null,
        ?int $recycleAboveMemoryBytes = null,
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
    ): Orchestrator {
        return new Orchestrator(
            NativeWorkerTransport::listen($workerCommand, $workingDirectory),
            new OrchestratorConfiguration(
                $recycleAfterTests,
                $recycleAboveMemoryBytes,
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
            ),
        );
    }
}
