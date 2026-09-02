<?php

declare(strict_types=1);

namespace Greenlight\Config;

use Greenlight\Plugin\PluginDefinition;
use Greenlight\Result\ResultPolicy;
use Greenlight\Result\RunPolicy;

/**
 * Defines policy, extensions, and retained output for test execution.
 *
 * @internal
 */
final readonly class ExecutionConfiguration
{
    /**
     * @param list<PluginDefinition> $plugins
     * @param positive-int|null $stopAfterFailures A null value does not stop the run for failed or errored tests.
     */
    public function __construct(
        public array $plugins,
        public ResultPolicy $policy,
        public RunPolicy $runPolicy,
        public ?int $stopAfterFailures,
        public ArtifactConfiguration $artifacts,
    ) {}
}
