<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\GracefulShutdown;
use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Reporting\Ticking;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\Integration\ProvisionedIntegrationFixtures;

/**
 * Contains orchestration policy and run collaborators.
 *
 * @internal
 */
final readonly class OrchestratorConfiguration
{
    public const float DEFAULT_CONNECT_DEADLINE_SECONDS = 30.0;
    public const float DEFAULT_PROGRESS_DEADLINE_SECONDS = 60.0;

    /**
     * @param positive-int|null $recycleAfterTests
     * @param positive-int|null $recycleAboveMemoryBytes
     * @param array<non-empty-string, positive-int> $resourceLimits
     */
    public function __construct(
        public ?int $recycleAfterTests = null,
        public ?int $recycleAboveMemoryBytes = null,
        public ?int $stopAfterFailures = null,
        public ?CoverageSettings $coverageSettings = null,
        public ?string $configFile = null,
        public bool $detectLeaks = false,
        public ?ResultPolicy $policy = null,
        public ?GracefulShutdown $shutdown = null,
        public ?Ticking $ticker = null,
        public ?ArtifactStore $artifactStore = null,
        public ?ArtifactConfiguration $artifactConfiguration = null,
        public ProvisionedIntegrationFixtures $integrationFixtures = new ProvisionedIntegrationFixtures(),
        public float $connectDeadlineSeconds = self::DEFAULT_CONNECT_DEADLINE_SECONDS,
        public float $progressDeadlineSeconds = self::DEFAULT_PROGRESS_DEADLINE_SECONDS,
        public array $resourceLimits = [],
        public InitialWorkerAssignment $initialWorkerAssignment = InitialWorkerAssignment::Progressive,
        public ?string $generatedCodeDirectory = null,
        public ?string $temporaryDirectory = null,
    ) {}
}
