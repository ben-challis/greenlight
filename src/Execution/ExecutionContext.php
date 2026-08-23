<?php

declare(strict_types=1);

namespace Greenlight\Execution;

use Greenlight\Config\ExecutionConfiguration;
use Greenlight\Config\StorageLayout;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\IntegrationFixture\ProvisionedIntegrationFixtures;

/**
 * Supplies run-owned state to one execution adapter.
 *
 * @internal
 */
final readonly class ExecutionContext
{
    /**
     * @param array<string, float> $classSeconds Recorded class durations.
     */
    public function __construct(
        public ExecutionConfiguration $execution,
        public ArtifactStore $artifacts,
        public ProvisionedIntegrationFixtures $fixtures,
        public array $classSeconds,
        public StorageLayout $storage,
    ) {}
}
