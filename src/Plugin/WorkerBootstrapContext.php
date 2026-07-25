<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Test\TestChannel;
use Greenlight\Harness\IntegrationResources;

/**
 * Worker-local view of orchestrator-provisioned integration resources.
 */
final readonly class WorkerBootstrapContext
{
    /**
     * @param non-empty-string $workerId
     */
    public function __construct(
        public string $workerId,
        public TestChannel $channel,
        public IntegrationResources $resources,
    ) {}
}
