<?php

declare(strict_types=1);

namespace Greenlight\Config;

use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Plugin\PluginDefinition;

/**
 * Defines policy, extensions, and retained output for test execution.
 *
 * @internal
 */
final readonly class ExecutionConfiguration
{
    /**
     * @param list<PluginDefinition> $plugins
     * @param positive-int|null $stopAfterFailures A null value runs all tests regardless of failures.
     */
    public function __construct(
        public array $plugins,
        public ResultPolicy $policy,
        public ?int $stopAfterFailures,
        public ArtifactConfiguration $artifacts,
    ) {}
}
